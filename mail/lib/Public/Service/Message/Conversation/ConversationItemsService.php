<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Conversation;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Dto\MailMessage;
use Bitrix\Mail\Internal\Repository\DraftRepository;
use Bitrix\Mail\Internal\Repository\MessageConversationListRepository;
use Bitrix\Mail\Internal\Service\Message\Conversation\ConversationDiagnostics;
use Bitrix\Mail\Public\Service\Message\Conversation\Dto\ConversationItem;
use Bitrix\Mail\Public\Service\Message\Conversation\Dto\ConversationItemsPage;
use Bitrix\Mail\Public\Service\Message\Conversation\Dto\ConversationItemsRequest;
use Bitrix\Mail\Public\Service\Message\Conversation\Dto\ConversationSummary;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

/**
 * One portion of an expanded conversation (API-02).
 *
 * The elements are the messages of the conversation and the reply drafts of the current user in it,
 * newest first. They are read from two ordered streams and merged into one portion, so a draft takes
 * its place among the messages by the moment it was written.
 *
 * Two scopes meet here and are deliberately kept apart. The elements are read over every folder of
 * the mailbox except spam and trash: an expansion must not hide a message of the conversation just
 * because it lies in another folder or did not match the search. The version of the portion, on the
 * contrary, is read over the folders the grid summarized the row over, so the client compares it with
 * the version it already holds and not with a value of another scope.
 *
 * Complete bodies, headers and attachment files are not read: the portion carries a capped preview
 * and an attachment count, while the existing view screen loads the message it opens.
 */
class ConversationItemsService
{
	public const ERROR_ACCESS_DENIED = ConversationMailboxAccessService::ERROR_ACCESS_DENIED;
	public const ERROR_MAILBOX_UNAVAILABLE = ConversationMailboxAccessService::ERROR_MAILBOX_UNAVAILABLE;
	public const ERROR_CONVERSATION_NOT_FOUND = 'CONVERSATION_NOT_FOUND';
	public const ERROR_STALE_CONVERSATION = 'STALE_CONVERSATION';
	public const ERROR_INVALID_CURSOR = 'INVALID_CURSOR';

	private const CURSOR_ROW_MESSAGE = 'm';
	private const CURSOR_ROW_DRAFT = 'd';

	public function __construct(
		private readonly MessageConversationListRepository $repository = new MessageConversationListRepository(),
		private readonly DraftRepository $draftRepository = new DraftRepository(),
		private readonly ConversationDiagnostics $diagnostics = new ConversationDiagnostics(),
		private readonly ConversationMailboxAccessService $mailboxAccess = new ConversationMailboxAccessService(),
	)
	{
	}

	/**
	 * @param int $userId current user of the server session, never taken from the request
	 * @return Result `getData()` holds the fields of the portion itself, without an envelope
	 */
	public function getPage(int $userId, ConversationItemsRequest $request): Result
	{
		$result = new Result();
		$accessResult = $this->mailboxAccess->check($request->mailboxId, $userId);
		if (!$accessResult->isSuccess())
		{
			return $result->addErrors($accessResult->getErrors());
		}
		if (!Feature::isMessageConversationGridAvailable())
		{
			return $result->addError(new Error(
				'The conversation grid is not available.',
				self::ERROR_STALE_CONVERSATION,
			));
		}
		if (!$this->repository->isProjectionReady($request->mailboxId))
		{
			return $result->addError(new Error(
				'The conversation projection of the mailbox is not ready.',
				self::ERROR_STALE_CONVERSATION,
			));
		}

		$conversationId = ConversationSummary::readConversationId($request->conversationId);
		if ($conversationId === null)
		{
			return $result->addError(new Error(
				'The conversation identifier is malformed.',
				self::ERROR_CONVERSATION_NOT_FOUND,
			));
		}

		$cursor = $this->decodeCursor($request->cursor);
		if ($request->cursor !== null && $request->cursor !== '' && $cursor === null)
		{
			return $result->addError(new Error('The cursor is malformed.', self::ERROR_INVALID_CURSOR));
		}

		$mailboxId = $this->repository->findConversationMailboxId($conversationId);
		if ($mailboxId !== $request->mailboxId)
		{
			return $result->addError(new Error(
				'The conversation is no longer available in the mailbox.',
				self::ERROR_STALE_CONVERSATION,
			));
		}

		$startedAt = microtime(true);
		$page = $this->buildPage($userId, $request, $conversationId, $cursor);
		$this->diagnostics->itemsServed(
			$request->mailboxId,
			$conversationId,
			(int)round((microtime(true) - $startedAt) * 1000),
			count($page->items),
		);

		return $result->setData($page->toArray());
	}

	/** @param array{date: DateTime, id: int, row: string}|null $cursor */
	private function buildPage(
		int $userId,
		ConversationItemsRequest $request,
		int $conversationId,
		?array $cursor,
	): ConversationItemsPage
	{
		$mailboxId = $request->mailboxId;
		$folders = array_filter(
			$this->repository->findSyncFolders($mailboxId),
			static fn(array $folder): bool => !$folder['isSpam'] && !$folder['isTrash'],
		);
		$visibleDirs = array_values(array_column($folders, 'md5'));
		if ($visibleDirs === [])
		{
			return ConversationItemsPage::empty($conversationId, ConversationVersion::of($conversationId, 0, 0, 0));
		}

		$limit = $cursor === null ? ConversationItemsRequest::FIRST_PAGE_SIZE : $request->boundedLimit();
		$rows = array_merge(
			$this->readMessageRows($mailboxId, $conversationId, $visibleDirs, $cursor, $limit),
			$this->readDraftRows($mailboxId, $userId, $conversationId, $visibleDirs, $cursor, $limit),
		);
		usort($rows, $this->rowOrder(...));
		$window = array_slice($rows, 0, $limit);

		$availableCount = $this->repository->countConversationMessagesAfter(
			$mailboxId,
			$conversationId,
			$visibleDirs,
			$this->streamCursor($cursor, self::CURSOR_ROW_MESSAGE),
		) + $this->repository->countConversationDraftsAfter(
			$mailboxId,
			$userId,
			$conversationId,
			$visibleDirs,
			$this->streamCursor($cursor, self::CURSOR_ROW_DRAFT),
		);
		$items = $this->buildItems($userId, $request, $folders, $window);
		$materializedRows = array_slice($window, 0, count($items));
		$remainingCount = max(0, $availableCount - count($materializedRows));
		$lastRow = $materializedRows === [] ? null : $materializedRows[count($materializedRows) - 1];
		$nextCursor = null;
		if ($remainingCount > 0)
		{
			$nextCursor = $lastRow === null ? $request->cursor : $this->encodeCursor($lastRow);
		}

		return new ConversationItemsPage(
			$conversationId,
			$items,
			$nextCursor,
			$remainingCount,
			count($items) !== count($window),
			$this->buildVersion($userId, $mailboxId, $conversationId, $folders, $request->filter),
		);
	}

	/**
	 * Elements of the portion in the order they were merged in. An element the read model of the row
	 * could not be built for is left out and reported by `incomplete`: it is available, but not yet
	 * whole, and a repeated request may read it.
	 *
	 * @param array<string, array{md5: string, name: string, isSpam: bool, isTrash: bool, isDraft: bool}> $folders
	 * @param list<array{kind: string, sortDate: int, sortRank: int, sortId: int}> $window
	 * @return ConversationItem[]
	 */
	private function buildItems(
		int $userId,
		ConversationItemsRequest $request,
		array $folders,
		array $window,
	): array
	{
		if ($window === [])
		{
			return [];
		}

		$mailboxId = $request->mailboxId;
		$visibleDirs = array_values(array_column($folders, 'md5'));
		$messageIds = $this->rowIdsOfKind($window, self::CURSOR_ROW_MESSAGE);
		$draftIds = $this->rowIdsOfKind($window, self::CURSOR_ROW_DRAFT);
		$messageRows = $this->repository->findMessageRows($mailboxId, $messageIds, $visibleDirs);
		$messageFolders = $this->repository->findMessageFolders($mailboxId, $messageIds, $visibleDirs);
		$drafts = $this->draftRepository->findActiveMailDraftsByIds($userId, $draftIds);
		$matchedFilter = ConversationFilter::forSearch(
			$mailboxId,
			$userId,
			ConversationFilter::supportedOnly($request->filter),
			$visibleDirs,
		);
		// Nothing narrows the elements of the expansion, so with no search running every one of them
		// belongs to what the grid is showing.
		$matchedIds = $matchedFilter === null
			? null
			: $this->repository->findMatchedMessageIds($mailboxId, $messageIds, $matchedFilter);
		$foldersByMd5 = $this->indexFoldersByMd5($folders);

		$items = [];
		foreach ($window as $row)
		{
			$item = $row['kind'] === self::CURSOR_ROW_MESSAGE
				? $this->buildMessageItem(
					$row['sortId'],
					$messageRows[$row['sortId']] ?? null,
					$foldersByMd5[$messageFolders[$row['sortId']] ?? ''] ?? null,
					$matchedIds === null || isset($matchedIds[$row['sortId']]),
				)
				: $this->buildDraftItem(
					$row['sortId'],
					$drafts[$row['sortId']]['view'] ?? null,
					$matchedFilter === null,
				);
			if ($item === null)
			{
				break;
			}

			$items[] = $item;
		}

		return $items;
	}

	/** @param array{path: string, name: string}|null $folder */
	private function buildMessageItem(
		int $messageId,
		?array $messageRow,
		?array $folder,
		bool $matched,
	): ?ConversationItem
	{
		if ($messageRow === null || $folder === null)
		{
			return null;
		}

		return ConversationItem::forMessage(
			$messageId,
			MailMessage::fromListRow($messageRow)->toArray(),
			$folder['path'],
			$folder['name'],
			$matched,
		);
	}

	private function buildDraftItem(int $draftId, ?array $draftView, bool $matched): ?ConversationItem
	{
		return $draftView === null ? null : ConversationItem::forDraft($draftId, $draftView, $matched);
	}

	/**
	 * @param string[] $visibleDirs
	 * @param array{date: DateTime, id: int, row: string}|null $cursor
	 * @return list<array{kind: string, sortDate: int, sortRank: int, sortId: int}>
	 */
	private function readMessageRows(
		int $mailboxId,
		int $conversationId,
		array $visibleDirs,
		?array $cursor,
		int $limit,
	): array
	{
		$rows = [];
		foreach (
			$this->repository->findConversationMessagePage(
				$mailboxId,
				$conversationId,
				$visibleDirs,
				$this->streamCursor($cursor, self::CURSOR_ROW_MESSAGE),
				$limit,
			) as $message
		)
		{
			$lastActivity = $message['lastActivity'] ?? null;
			if (!$lastActivity instanceof DateTime)
			{
				continue;
			}

			$rows[] = [
				'kind' => self::CURSOR_ROW_MESSAGE,
				'sortDate' => $lastActivity->getTimestamp(),
				'sortRank' => 0,
				'sortId' => $message['messageId'],
			];
		}

		return $rows;
	}

	/**
	 * @param string[] $visibleDirs
	 * @param array{date: DateTime, id: int, row: string}|null $cursor
	 * @return list<array{kind: string, sortDate: int, sortRank: int, sortId: int}>
	 */
	private function readDraftRows(
		int $mailboxId,
		int $userId,
		int $conversationId,
		array $visibleDirs,
		?array $cursor,
		int $limit,
	): array
	{
		return array_map(
			static fn(array $draft): array => [
				'kind' => self::CURSOR_ROW_DRAFT,
				'sortDate' => $draft['updatedAt']->getTimestamp(),
				'sortRank' => 1,
				'sortId' => $draft['draftId'],
			],
			$this->repository->findConversationDraftPage(
				$mailboxId,
				$userId,
				$conversationId,
				$visibleDirs,
				$this->streamCursor($cursor, self::CURSOR_ROW_DRAFT),
				$limit,
			),
		);
	}

	/**
	 * Version of the row of the grid this expansion belongs to.
	 *
	 * @param array<string, array{md5: string, name: string, isSpam: bool, isTrash: bool, isDraft: bool}> $folders
	 */
	private function buildVersion(
		int $userId,
		int $mailboxId,
		int $conversationId,
		array $folders,
		array $filter,
	): string
	{
		$facts = $this->repository->findConversationVersionFacts(
			$mailboxId,
			$userId,
			$conversationId,
			$this->resolveContextDirs($folders, $filter),
		);

		return ConversationVersion::of(
			$conversationId,
			$facts['messageCount'],
			$facts['representativeMessageId'],
			$facts['draftCount'],
			$facts['revision'],
			$facts['draftMembershipFingerprint'],
		);
	}

	/**
	 * Folders the grid summarized the row of this conversation over: a plain folder summarizes its
	 * own messages, while the drafts view and a virtual folder summarize every folder at once.
	 *
	 * @param array<string, array{md5: string, name: string, isSpam: bool, isTrash: bool, isDraft: bool}> $folders
	 * @return string[]
	 */
	private function resolveContextDirs(array $folders, array $filter): array
	{
		$path = isset($filter['DIR']) && is_scalar($filter['DIR']) ? (string)$filter['DIR'] : '';
		$folder = $folders[$path] ?? null;

		return $folder === null || $folder['isDraft']
			? array_values(array_column($folders, 'md5'))
			: [$folder['md5']];
	}

	/**
	 * @param array<string, array{md5: string, name: string}> $folders
	 * @return array<string, array{path: string, name: string}>
	 */
	private function indexFoldersByMd5(array $folders): array
	{
		$byMd5 = [];
		foreach ($folders as $path => $folder)
		{
			$byMd5[$folder['md5']] = ['path' => (string)$path, 'name' => $folder['name']];
		}

		return $byMd5;
	}

	/**
	 * @param list<array{kind: string, sortId: int}> $window
	 * @return int[]
	 */
	private function rowIdsOfKind(array $window, string $kind): array
	{
		return array_values(array_map(
			static fn(array $row): int => $row['sortId'],
			array_filter($window, static fn(array $row): bool => $row['kind'] === $kind),
		));
	}

	/**
	 * Bound of one of the two ordered streams of the portion. Both streams are ordered by the same
	 * date and are told apart by the kind of element, so the bound of the stream the cursor did not
	 * come from has no identifier of its own: it either covers the whole moment of the cursor or none
	 * of it.
	 *
	 * @param array{date: DateTime, id: int, row: string}|null $cursor
	 * @return array{date: DateTime, id: int}|null
	 */
	private function streamCursor(?array $cursor, string $row): ?array
	{
		if ($cursor === null)
		{
			return null;
		}
		if ($cursor['row'] === $row)
		{
			return ['date' => $cursor['date'], 'id' => $cursor['id']];
		}

		// Messages come before drafts within one moment: an identifier below every real one leaves the
		// moment behind, an identifier above every real one keeps the whole of it.
		return [
			'date' => $cursor['date'],
			'id' => $row === self::CURSOR_ROW_MESSAGE ? 0 : PHP_INT_MAX,
		];
	}

	/**
	 * Newest first; within one moment a message stands before a draft, and the larger identifier
	 * before the smaller one. The cursor of the portion repeats this order exactly, so a message
	 * arriving between two requests lands ahead of the bound and never repeats a delivered portion.
	 *
	 * @param array{sortDate: int, sortRank: int, sortId: int} $first
	 * @param array{sortDate: int, sortRank: int, sortId: int} $second
	 */
	private function rowOrder(array $first, array $second): int
	{
		return [$second['sortDate'], $first['sortRank'], $second['sortId']]
			<=> [$first['sortDate'], $second['sortRank'], $first['sortId']];
	}

	/** @param array{sortDate: int, sortId: int, kind: string} $row */
	private function encodeCursor(array $row): string
	{
		return base64_encode(Json::encode([
			'd' => $row['sortDate'],
			't' => $row['kind'],
			'i' => $row['sortId'],
		]));
	}

	/** @return array{date: DateTime, id: int, row: string}|null */
	private function decodeCursor(?string $cursor): ?array
	{
		if ($cursor === null || $cursor === '')
		{
			return null;
		}

		$decoded = base64_decode($cursor, true);
		if ($decoded === false)
		{
			return null;
		}

		try
		{
			$payload = Json::decode($decoded);
		}
		catch (\Throwable)
		{
			return null;
		}

		if (
			!is_array($payload)
			|| !isset($payload['d'], $payload['t'], $payload['i'])
			|| !is_int($payload['d'])
			|| !is_int($payload['i'])
			|| !in_array($payload['t'], [self::CURSOR_ROW_MESSAGE, self::CURSOR_ROW_DRAFT], true)
		)
		{
			return null;
		}

		return [
			'date' => DateTime::createFromTimestamp($payload['d']),
			'id' => $payload['i'],
			'row' => $payload['t'],
		];
	}
}

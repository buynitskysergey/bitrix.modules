<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Conversation;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Internal\Repository\MessageConversationListRepository;
use Bitrix\Mail\Internal\Service\Draft\DraftService;
use Bitrix\Mail\Internal\Service\Message\Conversation\ConversationMessageOperations;
use Bitrix\Mail\Internals\DraftTable;
use Bitrix\Mail\Public\Service\Message\Conversation\Dto\ConversationActionRequest;
use Bitrix\Mail\Public\Service\Message\Conversation\Dto\ConversationActionResult;
use Bitrix\Mail\Public\Service\Message\Conversation\Dto\ConversationSummary;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * A group action over one row of the conversation grid (API-03, ALG-02).
 *
 * The client never says which messages to change. It says which mailbox, which conversation, which
 * folder it is looking at and which composition of the row it drew; the elements are resolved here,
 * from the projection, and resolved again right before every single change. There is no path in which
 * a message is changed because an identifier of it arrived in the request: the request carries no
 * identifiers of messages at all, and the version it carries decides whether the action runs, never
 * what it runs on.
 *
 * Two boundaries hold the action together. It stays inside one physical folder, because a virtual
 * folder spans several of them and a group action of one would silently touch the rest; and it stays
 * inside the existing single-message operations, so counters, IMAP flags, the blacklist and the
 * cascades of a deletion keep the one owner they already have. Nothing about CRM bindings is carried
 * over or merged: a binding belongs to a single message and the operations leave it where it is.
 *
 * The answer is exact. Every element that was changed is named, every element that was refused is
 * named with a stable code, and a partial failure is never rounded up to a common success.
 */
class ConversationActionService
{
	public const ERROR_ACCESS_DENIED = ConversationMailboxAccessService::ERROR_ACCESS_DENIED;
	public const ERROR_MAILBOX_UNAVAILABLE = ConversationMailboxAccessService::ERROR_MAILBOX_UNAVAILABLE;
	public const ERROR_CONVERSATION_NOT_FOUND = 'CONVERSATION_NOT_FOUND';
	public const ERROR_INVALID_ACTION = 'INVALID_ACTION';
	public const ERROR_STALE_CONVERSATION = 'STALE_CONVERSATION';

	/** Elements handed to one call of an existing operation. */
	private const BATCH_SIZE = 50;

	/** Elements one request walks at most; what is left over makes the row one to read again. */
	private const MAX_TARGETS = 500;

	public function __construct(
		private readonly MessageConversationListRepository $repository = new MessageConversationListRepository(),
		private readonly ConversationMessageOperations $operations = new ConversationMessageOperations(),
		private readonly DraftService $draftService = new DraftService(),
		private readonly ConversationMailboxAccessService $mailboxAccess = new ConversationMailboxAccessService(),
		private readonly ConversationFolderActionPolicy $actionPolicy = new ConversationFolderActionPolicy(),
	)
	{
	}

	/**
	 * @param int $userId current user of the server session, never taken from the request
	 * @return Result `getData()` holds the fields of {@see ConversationActionResult}, without an envelope
	 */
	public function execute(int $userId, ConversationActionRequest $request): Result
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
		if (!in_array($request->action, $this->actionPolicy->allActions(), true))
		{
			return $result->addError(new Error('Unknown group action.', self::ERROR_INVALID_ACTION));
		}

		$conversationId = ConversationSummary::readConversationId($request->conversationId);
		if ($conversationId === null)
		{
			return $result->addError(new Error(
				'The conversation identifier is malformed.',
				self::ERROR_CONVERSATION_NOT_FOUND,
			));
		}

		$mailboxId = $this->repository->findConversationMailboxId($conversationId);
		if ($mailboxId !== $request->mailboxId)
		{
			return $result->addError(new Error(
				'The conversation is no longer available in the mailbox.',
				self::ERROR_STALE_CONVERSATION,
			));
		}

		$folders = $this->repository->findSyncFolders($request->mailboxId);
		$listedFolders = array_filter(
			$folders,
			static fn(array $folder): bool => !$folder['isSpam'] && !$folder['isTrash'],
		);
		$folder = $listedFolders[$request->folderPath] ?? null;
		if ($folder === null)
		{
			// A virtual folder names no physical one, and a folder of another mailbox is not here at
			// all: the set is built from the mailbox whose access has just been checked.
			return $result->addError(new Error(
				'The folder does not identify one physical folder of the grid.',
				self::ERROR_INVALID_ACTION,
			));
		}
		if (!in_array($request->action, $this->actionPolicy->availableActions($folder), true))
		{
			return $result->addError(new Error(
				'The action is not available in this folder.',
				self::ERROR_INVALID_ACTION,
			));
		}

		$targetFolderPath = '';
		if ($request->action === ConversationSummary::ACTION_MOVE)
		{
			$targetFolderPath = $request->targetFolderPath();
			if (!isset($folders[$targetFolderPath]) || $targetFolderPath === $request->folderPath)
			{
				return $result->addError(new Error(
					'The destination is not another synchronized folder of the mailbox.',
					self::ERROR_INVALID_ACTION,
				));
			}
		}

		$contextDirs = $folder['isDraft']
			? array_values(array_column($listedFolders, 'md5'))
			: [$folder['md5']];
		if ($request->expectedVersion !== $this->buildVersion($userId, $request->mailboxId, $conversationId, $contextDirs))
		{
			return $result->addError(new Error(
				'The composition of the conversation is not the one the client drew.',
				self::ERROR_STALE_CONVERSATION,
			));
		}

		$outcome = $folder['isDraft']
			? $this->executeOnDrafts($userId, $request, $conversationId, $contextDirs)
			: $this->executeOnMessages($userId, $request, $conversationId, $folder['md5'], $targetFolderPath);
		$version = $this->buildVersion($userId, $request->mailboxId, $conversationId, $contextDirs);

		$actionResult = (new ConversationActionResult(
			$conversationId,
			$outcome['processedMessageIds'],
			$outcome['processedDraftIds'],
			$outcome['failed'],
			$version,
			$outcome['failed'] !== []
				|| $outcome['truncated']
				|| ($this->touchesIncomingOnly($request->action) && $outcome['processedMessageIds'] !== [])
				|| $version !== $request->expectedVersion,
		))->toArray();
		$actionResult['truncated'] = $outcome['truncated'];

		return $result->setData($actionResult);
	}

	/**
	 * Messages of the conversation the folder shows, in bounded portions.
	 *
	 * @return array{processedMessageIds: int[], processedDraftIds: int[], failed: list<array{itemId: string, code: string}>, truncated: bool}
	 */
	private function executeOnMessages(
		int $userId,
		ConversationActionRequest $request,
		int $conversationId,
		string $dirMd5,
		string $targetFolderPath,
	): array
	{
		if ($request->action === ConversationSummary::ACTION_MARK_UNREAD)
		{
			// The newest incoming message of the folder alone becomes unread, however many messages the
			// conversation holds.
			return $this->collect([
				$this->applyToMessages(
					$userId,
					$request,
					$targetFolderPath,
					$this->repository->findNewestConversationUidRows($request->mailboxId, $conversationId, $dirMd5),
				),
			], false);
		}

		$batches = [];
		$afterUidId = '';
		$walked = 0;
		while ($walked < self::MAX_TARGETS)
		{
			$rows = $this->repository->findConversationUidRows(
				$request->mailboxId,
				$conversationId,
				$dirMd5,
				self::BATCH_SIZE,
				$afterUidId,
			);
			if ($rows === [])
			{
				return $this->collect($batches, false);
			}

			$walked += count($rows);
			$afterUidId = $rows[count($rows) - 1]['uidId'];
			$batches[] = $this->applyToMessages(
				$userId,
				$request,
				$targetFolderPath,
				$request->action === ConversationSummary::ACTION_MARK_READ ? $this->unreadOnly($rows) : $rows,
			);
			if (count($rows) < self::BATCH_SIZE)
			{
				return $this->collect($batches, false);
			}
		}

		$hasMore = $this->repository->findConversationUidRows(
			$request->mailboxId,
			$conversationId,
			$dirMd5,
			1,
			$afterUidId,
		) !== [];

		return $this->collect($batches, $hasMore);
	}

	/**
	 * One portion of messages, changed by the very operation the plain list uses.
	 *
	 * Access to the mailbox is checked again here, immediately before the change: the portion was read
	 * a moment ago, and rights can be revoked between reading and writing.
	 *
	 * @param list<array{uidId: string, messageId: int, isSeen: bool}> $rows
	 * @return array{processed: int[], failed: array<int, string>}
	 */
	private function applyToMessages(
		int $userId,
		ConversationActionRequest $request,
		string $targetFolderPath,
		array $rows,
	): array
	{
		$messageIds = array_values(array_unique(array_column($rows, 'messageId')));
		if ($messageIds === [])
		{
			return ['processed' => [], 'failed' => []];
		}
		if (!$this->mailboxAccess->check($request->mailboxId, $userId)->isSuccess())
		{
			return [
				'processed' => [],
				'failed' => array_fill_keys($messageIds, ConversationActionResult::FAILURE_ACCESS_LOST),
			];
		}

		$messageIdByCompositeId = [];
		foreach ($rows as $row)
		{
			$messageIdByCompositeId[$row['uidId'] . '-' . $request->mailboxId] = $row['messageId'];
		}

		$operationResult = $this->runOperation(
			$userId,
			$request->action,
			array_keys($messageIdByCompositeId),
			$targetFolderPath,
		);
		$processed = [];
		foreach ((array)($operationResult->getData()['processedIds'] ?? []) as $compositeId)
		{
			if (isset($messageIdByCompositeId[(string)$compositeId]))
			{
				$processed[$messageIdByCompositeId[(string)$compositeId]] = true;
			}
		}
		$processed = array_keys($processed);

		return [
			'processed' => $processed,
			'failed' => array_fill_keys(
				array_values(array_diff($messageIds, $processed)),
				ConversationActionResult::FAILURE_OPERATION_FAILED,
			),
		];
	}

	/** @param string[] $compositeIds */
	private function runOperation(int $userId, string $action, array $compositeIds, string $targetFolderPath): Result
	{
		return match ($action)
		{
			ConversationSummary::ACTION_MARK_READ => $this->operations->markSeen($compositeIds),
			ConversationSummary::ACTION_MARK_UNREAD => $this->operations->markUnseen($compositeIds),
			ConversationSummary::ACTION_MOVE => $this->operations->move($compositeIds, $targetFolderPath, $userId),
			ConversationSummary::ACTION_DELETE => $this->operations->delete($compositeIds, $userId),
			ConversationSummary::ACTION_MARK_SPAM => $this->operations->markSpam($compositeIds, $userId),
		};
	}

	/**
	 * Active reply drafts of the current user in the conversation, in bounded portions and deleted by
	 * the service that owns drafts. A draft of another owner, an expired one and one already completed
	 * are not among them: the portions are read through the very scope the drafts view is built from.
	 *
	 * @param string[] $contextDirs
	 * @return array{processedMessageIds: int[], processedDraftIds: int[], failed: list<array{itemId: string, code: string}>, truncated: bool}
	 */
	private function executeOnDrafts(
		int $userId,
		ConversationActionRequest $request,
		int $conversationId,
		array $contextDirs,
	): array
	{
		$processed = [];
		$failed = [];
		$cursor = null;
		$walked = 0;
		while ($walked < self::MAX_TARGETS)
		{
			$drafts = $this->repository->findConversationDraftPage(
				$request->mailboxId,
				$userId,
				$conversationId,
				$contextDirs,
				$cursor,
				self::BATCH_SIZE,
			);
			if ($drafts === [])
			{
				return $this->draftOutcome($processed, $failed, false);
			}

			$walked += count($drafts);
			$lastDraft = $drafts[count($drafts) - 1];
			$cursor = ['date' => $lastDraft['updatedAt'], 'id' => $lastDraft['draftId']];
			$draftIds = array_column($drafts, 'draftId');
			if (!$this->mailboxAccess->check($request->mailboxId, $userId)->isSuccess())
			{
				foreach ($draftIds as $draftId)
				{
					$failed[$draftId] = ConversationActionResult::FAILURE_ACCESS_LOST;
				}

				return $this->draftOutcome($processed, $failed, false);
			}

			$deleted = array_map(
				'intval',
				(array)($this->draftService
					->deleteMany($userId, $draftIds, DraftTable::CONTEXT_MAIL)
					->getData()['deletedIds'] ?? []),
			);
			$processed = array_merge($processed, $deleted);
			foreach (array_diff($draftIds, $deleted) as $draftId)
			{
				$failed[$draftId] = ConversationActionResult::FAILURE_OPERATION_FAILED;
			}
			if (count($drafts) < self::BATCH_SIZE)
			{
				return $this->draftOutcome($processed, $failed, false);
			}
		}

		$hasMore = $this->repository->findConversationDraftPage(
			$request->mailboxId,
			$userId,
			$conversationId,
			$contextDirs,
			$cursor,
			1,
		) !== [];

		return $this->draftOutcome($processed, $failed, $hasMore);
	}

	/**
	 * @param int[] $processed
	 * @param array<int, string> $failed
	 * @return array{processedMessageIds: int[], processedDraftIds: int[], failed: list<array{itemId: string, code: string}>, truncated: bool}
	 */
	private function draftOutcome(array $processed, array $failed, bool $truncated): array
	{
		return [
			'processedMessageIds' => [],
			'processedDraftIds' => array_values(array_unique($processed)),
			'failed' => array_map(
				static fn(int $draftId): array => ConversationActionResult::draftFailure($draftId, $failed[$draftId]),
				array_keys($failed),
			),
			'truncated' => $truncated,
		];
	}

	/**
	 * Portions of one run merged into the answer. An element counts as failed only when no portion ever
	 * managed to change it: the same message can be met in two portions, once for every row of it the
	 * folder holds.
	 *
	 * @param list<array{processed: int[], failed: array<int, string>}> $batches
	 * @return array{processedMessageIds: int[], processedDraftIds: int[], failed: list<array{itemId: string, code: string}>, truncated: bool}
	 */
	private function collect(array $batches, bool $truncated): array
	{
		$processed = [];
		$failed = [];
		foreach ($batches as $batch)
		{
			foreach ($batch['processed'] as $messageId)
			{
				$processed[$messageId] = true;
			}
			foreach ($batch['failed'] as $messageId => $code)
			{
				$failed[$messageId] = $code;
			}
		}

		$failed = array_diff_key($failed, $processed);

		return [
			'processedMessageIds' => array_keys($processed),
			'processedDraftIds' => [],
			'failed' => array_map(
				static fn(int $messageId): array => ConversationActionResult::messageFailure(
					$messageId,
					$failed[$messageId],
				),
				array_keys($failed),
			),
			'truncated' => $truncated,
		];
	}

	/**
	 * @param list<array{uidId: string, messageId: int, isSeen: bool}> $rows
	 * @return list<array{uidId: string, messageId: int, isSeen: bool}>
	 */
	private function unreadOnly(array $rows): array
	{
		return array_values(array_filter($rows, static fn(array $row): bool => !$row['isSeen']));
	}

	private function touchesIncomingOnly(string $action): bool
	{
		return in_array(
			$action,
			[ConversationSummary::ACTION_MARK_READ, ConversationSummary::ACTION_MARK_UNREAD],
			true,
		);
	}

	/**
	 * Composition of the row of the grid the action belongs to, read over the very folders that row is
	 * summarized over, so that the client compares it with the value it already holds.
	 *
	 * @param string[] $contextDirs
	 */
	private function buildVersion(int $userId, int $mailboxId, int $conversationId, array $contextDirs): string
	{
		$facts = $this->repository->findConversationVersionFacts($mailboxId, $userId, $conversationId, $contextDirs);

		return ConversationVersion::of(
			$conversationId,
			$facts['messageCount'],
			$facts['representativeMessageId'],
			$facts['draftCount'],
			$facts['membershipFingerprint'],
			$facts['draftMembershipFingerprint'],
		);
	}
}

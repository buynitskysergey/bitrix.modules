<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Conversation;

use Bitrix\Mail\Helper\Dto\MailMessage;
use Bitrix\Mail\Helper\Message\Loader\MessageLoader;
use Bitrix\Mail\Helper\MessageFolder;
use Bitrix\Mail\Internal\Repository\DraftRepository;
use Bitrix\Mail\Internal\Repository\MessageConversationListRepository;
use Bitrix\Mail\Internal\Service\Message\Conversation\ConversationDiagnostics;
use Bitrix\Mail\Public\Service\Message\Conversation\Dto\ConversationListPage;
use Bitrix\Mail\Public\Service\Message\Conversation\Dto\ConversationListRequest;
use Bitrix\Mail\Public\Service\Message\Conversation\Dto\ConversationSettings;
use Bitrix\Mail\Public\Service\Message\Conversation\Dto\ConversationSummary;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

/**
 * One page of the mail grid grouped by conversations (API-01).
 *
 * The page is built in three steps: the conversations of the current context are selected and paged
 * in the database, their summaries are then read in a fixed number of batched statements, and only
 * the row model the client already knows is returned. The number of statements does not depend on
 * the number of groups on the page.
 *
 * Grouping is never forced: an unavailable feature, a user who turned it off, a projection that is
 * still being built and every context this page cannot serve answer with `grouped = false`, and the
 * caller keeps its plain list.
 */
class ConversationListService
{
	public const ERROR_ACCESS_DENIED = ConversationMailboxAccessService::ERROR_ACCESS_DENIED;
	public const ERROR_MAILBOX_UNAVAILABLE = ConversationMailboxAccessService::ERROR_MAILBOX_UNAVAILABLE;
	public const ERROR_PROJECTION_NOT_READY = 'PROJECTION_NOT_READY';
	public const ERROR_INVALID_FILTER = 'INVALID_FILTER';

	/** Spellings of the newest-first order of the list; the clients name that very order differently. */
	private const SORT_FIELDS = ['', 'date', 'receive_date', 'lastactivityat'];
	private const PREVIEW_LENGTH = 200;
	private const PARTICIPANTS_LIMIT = 5;
	private const CURSOR_ROW_CONVERSATION = 'c';
	private const CURSOR_ROW_DRAFT = 'd';

	public function __construct(
		private readonly MessageConversationListRepository $repository = new MessageConversationListRepository(),
		private readonly DraftRepository $draftRepository = new DraftRepository(),
		private readonly ConversationSettingsService $settingsService = new ConversationSettingsService(),
		private readonly ConversationDiagnostics $diagnostics = new ConversationDiagnostics(),
		private readonly ConversationMailboxAccessService $mailboxAccess = new ConversationMailboxAccessService(),
		private readonly ConversationFolderActionPolicy $actionPolicy = new ConversationFolderActionPolicy(),
	)
	{
	}

	/**
	 * @param int $userId current user of the server session, never taken from the request
	 * @return Result `getData()` holds the fields of the page itself, without an envelope
	 */
	public function getPage(int $userId, ConversationListRequest $request): Result
	{
		$result = new Result();
		$accessResult = $this->mailboxAccess->check($request->mailboxId, $userId);
		if (!$accessResult->isSuccess())
		{
			return $result->addErrors($accessResult->getErrors());
		}

		$unsupportedKeys = ConversationFilter::unsupportedKeys($request->filter);
		if ($unsupportedKeys !== [])
		{
			return $result->addError(new Error(
				'Unsupported filter fields: ' . implode(', ', $unsupportedKeys),
				self::ERROR_INVALID_FILTER,
			));
		}

		$cursor = $this->decodeCursor($request->cursor);
		if ($request->cursor !== null && $cursor === null)
		{
			return $result->addError(new Error('The cursor is malformed.', self::ERROR_INVALID_FILTER));
		}

		$settings = $this->settingsService->readSettings($userId, ConversationSettingsService::PLATFORM_MOBILE);
		$reportedSettings = $this->reportSettings($settings);
		if (!$settings->isGroupingActive() || !$this->isSupportedSort($request->sort))
		{
			return $result->setData(ConversationListPage::plain($reportedSettings)->toArray());
		}
		if (!$this->repository->isProjectionReady($request->mailboxId))
		{
			$result->setData(ConversationListPage::plain($reportedSettings)->toArray());

			return $result->addError(
				new Error('The conversation projection of the mailbox is not ready.', self::ERROR_PROJECTION_NOT_READY),
			);
		}

		$scope = $this->resolveScope($request);
		if ($scope === null)
		{
			return $result->setData(ConversationListPage::plain($reportedSettings)->toArray());
		}

		// Only the grouped answer is measured: it is the one the budgets of the feature are about.
		$startedAt = microtime(true);
		$page = $this->buildPage($userId, $request, $scope, $cursor, $reportedSettings);
		$this->diagnostics->listServed(
			$request->mailboxId,
			(int)round((microtime(true) - $startedAt) * 1000),
			count($page->items),
		);

		return $result->setData($page->toArray());
	}

	/**
	 * @param array{dirMd5List: string[], isDrafts: bool, actions: string[]} $scope
	 * @param array{date: DateTime, id: int, row: string}|null $cursor
	 * @param array{enabled: bool, onboardingSeen: bool, featureAvailable: bool} $settings
	 */
	private function buildPage(
		int $userId,
		ConversationListRequest $request,
		array $scope,
		?array $cursor,
		array $settings,
	): ConversationListPage
	{
		$limit = max(1, min($request->limit, ConversationListRequest::MAX_LIMIT));
		$matchedFilter = ConversationFilter::forSearch(
			$request->mailboxId,
			$userId,
			ConversationFilter::supportedOnly($request->filter),
			$scope['dirMd5List'],
		);
		if ($scope['isDrafts'] && $matchedFilter !== null)
		{
			// A search over drafts is answered by the plain drafts list: the filter of the grid
			// selects messages, and a standalone draft is not one.
			return ConversationListPage::plain($settings);
		}

		$conversations = $scope['isDrafts']
			? $this->repository->findDraftConversationPage(
				$request->mailboxId,
				$userId,
				$scope['dirMd5List'],
				$this->streamCursor($cursor, self::CURSOR_ROW_CONVERSATION),
				$limit + 1,
			)
			: $this->repository->findConversationPage(
				$request->mailboxId,
				$scope['dirMd5List'],
				$matchedFilter,
				$this->streamCursor($cursor, self::CURSOR_ROW_CONVERSATION),
				$limit + 1,
			);

		$rows = $this->buildConversationRows($userId, $request, $scope, $matchedFilter, $conversations);
		if ($scope['isDrafts'])
		{
			$rows = array_merge($rows, $this->buildStandaloneDraftRows($userId, $request, $scope, $cursor, $limit + 1));
			usort($rows, $this->rowOrder(...));
		}

		$hasMore = count($rows) > $limit;
		$rows = array_slice($rows, 0, $limit);
		$lastRow = $rows === [] ? null : $rows[count($rows) - 1];

		return new ConversationListPage(
			array_column($rows, 'item'),
			$hasMore && $lastRow !== null ? $this->encodeCursor($lastRow) : null,
			true,
			$settings,
		);
	}

	/**
	 * Summaries of the whole page in a fixed number of statements.
	 *
	 * @param array{dirMd5List: string[], isDrafts: bool, actions: string[]} $scope
	 * @param list<array{conversationId: int, lastActivity: DateTime}> $conversations
	 * @return list<array{item: ConversationSummary, sortDate: int, sortRank: int, sortId: int}>
	 */
	private function buildConversationRows(
		int $userId,
		ConversationListRequest $request,
		array $scope,
		?array $matchedFilter,
		array $conversations,
	): array
	{
		if ($conversations === [])
		{
			return [];
		}

		$mailboxId = $request->mailboxId;
		$dirMd5List = $scope['dirMd5List'];
		$conversationIds = array_column($conversations, 'conversationId');
		$counts = $this->repository->countConversationMessages($mailboxId, $conversationIds, $dirMd5List);
		$revisions = $this->repository->findConversationRevisions($mailboxId, $conversationIds);
		$draftCounts = $this->repository->countReplyDrafts($mailboxId, $userId, $conversationIds, $dirMd5List);
		$draftMembershipFingerprints = $this->repository->findConversationDraftMembershipFingerprints(
			$mailboxId,
			$userId,
			$conversationIds,
			$dirMd5List,
		);
		$representativeConversations = $conversations;
		if ($scope['isDrafts'])
		{
			$latestMessageActivities = $this->repository->findLatestMessageActivities(
				$mailboxId,
				$conversationIds,
				$dirMd5List,
			);
			$representativeConversations = array_map(
				static fn(array $conversation): array => [
					'conversationId' => $conversation['conversationId'],
					'lastActivity' => $latestMessageActivities[$conversation['conversationId']]
						?? $conversation['lastActivity'],
				],
				$conversations,
			);
		}
		$representatives = $this->repository->findRepresentativeMessageIds(
			$mailboxId,
			$representativeConversations,
			$dirMd5List,
		);
		$matchedCounts = $matchedFilter === null
			? []
			: $this->repository->countMatchedMessages($mailboxId, $conversationIds, $matchedFilter);
		// A single message keeps the favorite mark of the plain list before it is converted to the
		// shared lightweight mobile contract.
		$messageRows = MessageLoader::decorateListRows(
			$this->repository->findMessageRows(
				$mailboxId,
				array_values(array_filter($representatives)),
				$dirMd5List,
			),
			$userId,
			false,
		);

		$rows = [];
		foreach ($conversations as $conversation)
		{
			$conversationId = $conversation['conversationId'];
			$messageId = $representatives[$conversationId] ?? 0;
			$messageRow = $messageRows[$messageId] ?? null;
			$messageCount = $counts[$conversationId]['messageCount'] ?? 0;
			$unreadCount = $counts[$conversationId]['unreadCount'] ?? 0;
			$draftCount = $draftCounts[$conversationId] ?? 0;
			$matchedCount = $matchedFilter === null
				? $messageCount
				: ($matchedCounts[$conversationId] ?? 0);
			$lastActivity = $conversation['lastActivity'];
			$version = $this->buildVersion(
				$conversationId,
				$messageCount,
				$messageId,
				$draftCount,
				$revisions[$conversationId] ?? 0,
				$draftMembershipFingerprints[$conversationId] ?? '',
			);

			$item = $messageCount === 1 && $draftCount === 0 && $messageRow !== null
				? ConversationSummary::forMessage(
					$messageId,
					MailMessage::fromListRow($messageRow)->toArray(),
					$this->readSubject($messageRow),
					$this->buildPreview($messageRow),
					$this->buildParticipants($messageRow),
					$lastActivity->format(DATE_ATOM),
					$unreadCount,
					$matchedCount,
					$scope['actions'],
					$version,
				)
				: ConversationSummary::forConversation(
					$conversationId,
					$messageId,
					$this->readSubject($messageRow),
					$this->buildPreview($messageRow),
					$this->buildParticipants($messageRow),
					$lastActivity->format(DATE_ATOM),
					$messageCount + $draftCount,
					$unreadCount,
					$draftCount > 0,
					$matchedCount,
					$scope['actions'],
					$messageRow === null,
					$version,
				);

			$rows[] = [
				'item' => $item,
				'sortDate' => $lastActivity->getTimestamp(),
				'sortRank' => 0,
				'sortId' => $conversationId,
				'cursorRow' => self::CURSOR_ROW_CONVERSATION,
			];
		}

		return $rows;
	}

	/**
	 * Rows of the drafts view that stay drafts: a new or forwarded draft, and a reply whose parent
	 * message the current scope does not show.
	 *
	 * @param array{dirMd5List: string[], isDrafts: bool, actions: string[]} $scope
	 * @param array{date: DateTime, id: int, row: string}|null $cursor
	 * @return list<array{item: ConversationSummary, sortDate: int, sortRank: int, sortId: int}>
	 */
	private function buildStandaloneDraftRows(
		int $userId,
		ConversationListRequest $request,
		array $scope,
		?array $cursor,
		int $limit,
	): array
	{
		$draftCursor = $this->streamCursor($cursor, self::CURSOR_ROW_DRAFT);
		$draftIds = $this->repository->findStandaloneDraftPage(
			$request->mailboxId,
			$userId,
			$scope['dirMd5List'],
			$draftCursor,
			$limit,
		);
		$drafts = $this->draftRepository->findActiveMailDraftsByIds($userId, $draftIds);
		$rows = [];
		foreach ($draftIds as $draftId)
		{
			$draft = $drafts[$draftId] ?? null;
			if ($draft === null)
			{
				continue;
			}

			$view = $draft['view'];
			$rows[] = [
				'item' => ConversationSummary::forDraft(
					$draftId,
					$view,
					(string)$view['subject'],
					(string)$view['bodyPreview'],
					$this->buildDraftParticipants($view),
					$draft['updatedAt']->format(DATE_ATOM),
					$scope['actions'],
					$this->buildVersion($draftId, 1, 0, (int)$view['revision']),
				),
				'sortDate' => $draft['updatedAt']->getTimestamp(),
				'sortRank' => 1,
				'sortId' => $draftId,
				'cursorRow' => self::CURSOR_ROW_DRAFT,
			];
		}

		return $rows;
	}

	/**
	 * Folders the summary is computed over, and the group mutations the context allows.
	 *
	 * @return array{dirMd5List: string[], isDrafts: bool, actions: string[]}|null null for a context
	 *         this page does not serve, so the caller keeps its plain list
	 */
	private function resolveScope(ConversationListRequest $request): ?array
	{
		$folders = $this->repository->findSyncFolders($request->mailboxId);
		if ($folders === [])
		{
			return null;
		}

		$listedFolders = array_filter(
			$folders,
			static fn(array $folder): bool => !$folder['isSpam'] && !$folder['isTrash'],
		);
		if ($listedFolders === [])
		{
			return null;
		}

		if ($request->virtualFolder !== null)
		{
			return $request->virtualFolder === MessageFolder::VIRTUAL_ALL_MESSAGES
				? [
					'dirMd5List' => array_values(array_column($listedFolders, 'md5')),
					'isDrafts' => false,
					// Several physical folders at once: a group mutation of one of them would
					// silently touch the rest.
					'actions' => [],
				]
				: null;
		}

		$folder = $listedFolders[(string)$request->folder] ?? null;
		if ($folder === null)
		{
			return null;
		}

		return $folder['isDraft']
			? [
				'dirMd5List' => array_values(array_column($listedFolders, 'md5')),
				'isDrafts' => true,
				'actions' => $this->actionPolicy->availableActions($folder),
			]
			: [
				'dirMd5List' => [$folder['md5']],
				'isDrafts' => false,
				'actions' => $this->actionPolicy->availableActions($folder),
			];
	}

	/** Grouping keeps one order of its own, so any other sorting is served by the plain list. */
	private function isSupportedSort(array $sort): bool
	{
		if ($sort === [])
		{
			return true;
		}

		return in_array(mb_strtolower((string)($sort['field'] ?? '')), self::SORT_FIELDS, true)
			&& mb_strtolower((string)($sort['direction'] ?? 'desc')) === 'desc';
	}

	/**
	 * Settings the page reports (API-01).
	 *
	 * A request for the grid names no platform, and the onboarding tour is a mark of one client, so
	 * the reported mark is the mobile one: today the mobile client is the only one that shows the
	 * tour. A client with a tour of its own reads `mail.api.messageconversation.getSettings` for its
	 * own platform instead of this field.
	 *
	 * @return array{enabled: bool, onboardingSeen: bool, featureAvailable: bool}
	 */
	private function reportSettings(ConversationSettings $settings): array
	{
		return [
			'enabled' => $settings->enabled,
			'onboardingSeen' => $settings->onboardingSeen,
			'featureAvailable' => $settings->featureAvailable,
		];
	}

	/**
	 * Bound of one of the two ordered streams of the page. The streams are ordered by the same date
	 * and are told apart by the kind of row, so the bound of the stream the cursor did not come from
	 * has no identifier of its own: it either covers the whole moment of the cursor or none of it.
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

		// Conversations come before drafts within one moment: an identifier below every real one
		// leaves the moment behind, an identifier above every real one keeps the whole of it.
		return [
			'date' => $cursor['date'],
			'id' => $row === self::CURSOR_ROW_CONVERSATION ? 0 : PHP_INT_MAX,
		];
	}

	/**
	 * Newest first; within one moment a conversation stands before a draft, and the larger
	 * identifier before the smaller one. The cursor of the page repeats this order exactly.
	 *
	 * @param array{sortDate: int, sortRank: int, sortId: int} $first
	 * @param array{sortDate: int, sortRank: int, sortId: int} $second
	 */
	private function rowOrder(array $first, array $second): int
	{
		return [$second['sortDate'], $first['sortRank'], $second['sortId']]
			<=> [$first['sortDate'], $second['sortRank'], $first['sortId']];
	}

	/** @param array{sortDate: int, sortId: int, cursorRow: string} $row */
	private function encodeCursor(array $row): string
	{
		return base64_encode(Json::encode([
			'd' => $row['sortDate'],
			't' => $row['cursorRow'],
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
			|| !in_array($payload['t'], [self::CURSOR_ROW_CONVERSATION, self::CURSOR_ROW_DRAFT], true)
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

	private function readSubject(?array $messageRow): string
	{
		return (string)($messageRow['SUBJECT'] ?? '');
	}

	private function buildPreview(?array $messageRow): string
	{
		$plainBody = trim((string)preg_replace(
			'/\s+/u',
			' ',
			strip_tags(html_entity_decode((string)($messageRow['BODY_PREVIEW'] ?? ''), ENT_QUOTES | ENT_HTML5)),
		));

		return mb_substr($plainBody, 0, self::PREVIEW_LENGTH);
	}

	/**
	 * Participants of the summary: the addresses of its newest available element, deduplicated by
	 * mailbox address.
	 *
	 * @return array<int, array{name: string, email: string}>
	 */
	private function buildParticipants(?array $messageRow): array
	{
		if ($messageRow === null)
		{
			return [];
		}

		$participants = [];
		foreach ([$messageRow['FIELD_FROM'] ?? '', $messageRow['FIELD_TO'] ?? ''] as $field)
		{
			foreach (MessageLoader::buildContactList($field) as $contact)
			{
				$email = mb_strtolower($contact->email);
				if ($email === '' || isset($participants[$email]) || count($participants) >= self::PARTICIPANTS_LIMIT)
				{
					continue;
				}

				$participants[$email] = ['name' => (string)$contact->name, 'email' => (string)$contact->email];
			}
		}

		return array_values($participants);
	}

	/**
	 * @return array<int, array{name: string, email: string}>
	 */
	private function buildDraftParticipants(array $draftView): array
	{
		$contacts = array_merge([$draftView['sender'] ?? null], $draftView['recipients'] ?? []);
		$participants = [];
		foreach (array_filter($contacts, 'is_array') as $contact)
		{
			$email = mb_strtolower((string)($contact['email'] ?? ''));
			if ($email === '' || isset($participants[$email]) || count($participants) >= self::PARTICIPANTS_LIMIT)
			{
				continue;
			}

			$participants[$email] = ['name' => (string)($contact['name'] ?? ''), 'email' => (string)$contact['email']];
		}

		return array_values($participants);
	}

	/**
	 * Composition of the row. The expansion of that row answers with the very same value, so the
	 * two are built in one place.
	 */
	private function buildVersion(
		int $rowId,
		int $messageCount,
		int $representativeMessageId,
		int $draftCount,
		int $revision = 0,
		string $draftMembershipFingerprint = '',
	): string
	{
		return ConversationVersion::of(
			$rowId,
			$messageCount,
			$representativeMessageId,
			$draftCount,
			$revision,
			$draftMembershipFingerprint,
		);
	}
}

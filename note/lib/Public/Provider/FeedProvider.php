<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider;

use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Entity\History\Event;
use Bitrix\Note\Internal\Exceptions\AccessDeniedException;
use Bitrix\Note\Internal\Exceptions\DocumentNotFoundException;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\DocumentVersionRepository;
use Bitrix\Note\Internal\Repository\EventAuthorRepository;
use Bitrix\Note\Internal\Repository\EventRepository;
use Bitrix\Note\Internal\Service\History\AuthorResolver;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\History\EventVisibilityPolicy;
use Bitrix\Note\Internal\Service\User\IdentityColor;

/**
 * [P2.T2 / API-04] Reads the activity feed of one document. Role visibility
 * (MTX-01, EventVisibilityPolicy) is applied BEFORE the caller-supplied type
 * filter — the filter can only narrow what the role already sees, it never
 * widens it. Keyset pagination on the (CREATED_AT, ID) pair via
 * IX_NOTE_EVENT_FEED_TS, `limit+1` for hasNextPage (same shape as
 * DocumentProvider's other listings).
 */
final class FeedProvider
{
	private const EVENT_TYPE_CONTENT_CHANGED = 'content_changed';
	private const EVENT_TYPE_CREATED = 'created';

	// Lazily resolved (not a constructor default) to break the construction cycle
	// FeedProvider -> EventLogService -> HistoryPullGateway -> FeedProvider.
	private ?EventLogService $eventLogService;

	public function __construct(
		private readonly DocumentProvider $documentProvider = new DocumentProvider(),
		private readonly EventRepository $eventRepository = new EventRepository(),
		private readonly EventAuthorRepository $eventAuthorRepository = new EventAuthorRepository(),
		private readonly DocumentVersionRepository $versionRepository = new DocumentVersionRepository(),
		private readonly AuthorResolver $authorResolver = new AuthorResolver(),
		?EventLogService $eventLogService = null,
	)
	{
		$this->eventLogService = $eventLogService;
	}

	/**
	 * Enriches a single already-persisted event to the same actor/authors/versionAvailable
	 * tile shape used by listByDocument's items. Used by HistoryPullGateway to build the
	 * live-push payload for the history sidebar: no role/type filtering here (that only
	 * applies to a paginated read) — the tile is broadcast as-is to every tag watcher.
	 *
	 * @return array{
	 *   id: int, type: string, actor: array{id:int,name:string,avatar:?string,color:string},
	 *   createdAt: string, versionId: ?int, versionAvailable: bool,
	 *   authors?: array<int, array{id:int,name:string,avatar:?string,color:string}>
	 * }
	 */
	public function enrichOne(Event $event): array
	{
		return $this->enrich([$event])[0];
	}

	/**
	 * @param string[] $types empty = "select all" (every role-allowed type); otherwise the
	 *                         intersection with the role's allowlist (a filter never widens
	 *                         visibility)
	 * @param array{createdAt?: string, id: int}|null $afterCursor opaque cursor echoed back
	 *                         from a previous nextCursor
	 * @return array{
	 *   events: array<int, array{
	 *     id: int, type: string, actor: array{id:int,name:string,avatar:?string,color:string},
	 *     createdAt: string, versionId: ?int, versionAvailable: bool,
	 *     authors?: array<int, array{id:int,name:string,avatar:?string,color:string}>
	 *   }>,
	 *   nextCursor: ?array{createdAt: string, id: int},
	 *   allowedTypes: string[]
	 * }
	 * @throws DocumentNotFoundException
	 * @throws AccessDeniedException current user lacks LEVEL_VIEW on the document
	 */
	public function listByDocument(int $documentId, array $types, ?array $afterCursor, int $limit): array
	{
		$ownership = $this->documentProvider->getOwnershipInfo($documentId);
		if ($ownership === null)
		{
			throw new DocumentNotFoundException();
		}

		$snapshot = DocumentAccessService::getCurrentUserSnapshot($documentId, $ownership['collectionId']);
		if (!$snapshot['canView'])
		{
			throw new AccessDeniedException();
		}

		$limit = max(1, min(200, $limit));
		$allowedTypes = EventVisibilityPolicy::allowedTypes($snapshot);
		$allowedTypesOut = array_values($allowedTypes);

		// First page only: lazily register the historical 'created' event for documents
		// that predate the history feature. Best-effort — a failure must not break reads.
		if ($afterCursor === null)
		{
			$this->backfillCreatedEvent($documentId, $ownership);
		}

		$typesFilter = $this->resolveTypesFilter($types, $allowedTypes);
		if (empty($typesFilter))
		{
			return ['events' => [], 'nextCursor' => null, 'allowedTypes' => $allowedTypesOut];
		}

		[$afterCreatedAt, $afterId] = $this->parseCursor($afterCursor);

		$rows = $this->eventRepository->listByEntity(
			EventTable::SCOPE_DOCUMENT,
			$documentId,
			$typesFilter,
			$afterCreatedAt,
			$afterId,
			$limit + 1,
		);

		$hasNextPage = count($rows) > $limit;
		$page = $hasNextPage ? array_slice($rows, 0, $limit) : $rows;

		$nextCursor = null;
		if ($hasNextPage && !empty($page))
		{
			/** @var Event $last */
			$last = end($page);
			$nextCursor = [
				'createdAt' => $last->getCreatedAt()->format('c'),
				'id' => (int)$last->getId(),
			];
		}

		return [
			'events' => $this->enrich($page),
			'nextCursor' => $nextCursor,
			'allowedTypes' => $allowedTypesOut,
		];
	}

	/**
	 * @param string[] $types
	 * @param string[] $allowedTypes
	 * @return string[]
	 */
	private function resolveTypesFilter(array $types, array $allowedTypes): array
	{
		$types = array_values(array_filter($types, 'is_string'));
		if (empty($types))
		{
			// Empty filter = "Select all" in the UI = the role's own allowlist, unfiltered.
			return $allowedTypes;
		}

		// Intersecting with the role allowlist both sanitizes unknown strings and enforces
		// that an explicit filter can only narrow visibility, never widen it.
		return array_values(array_intersect($allowedTypes, $types));
	}

	/**
	 * @param array{createdAt?: string, id?: int}|null $afterCursor
	 * @return array{0: ?DateTime, 1: ?int} [afterCreatedAt, afterId]
	 */
	private function parseCursor(?array $afterCursor): array
	{
		$id = isset($afterCursor['id']) ? (int)$afterCursor['id'] : 0;
		if ($id <= 0)
		{
			return [null, null];
		}

		$createdAt = null;
		$rawCreatedAt = $afterCursor['createdAt'] ?? null;
		if (is_string($rawCreatedAt) && $rawCreatedAt !== '')
		{
			try
			{
				$createdAt = DateTime::createFromPhp(new \DateTime($rawCreatedAt));
			}
			catch (\Throwable)
			{
				$createdAt = null;
			}
		}

		return [$createdAt, $id];
	}

	private function backfillCreatedEvent(int $documentId, array $ownership): void
	{
		try
		{
			if (
				!Configuration::isActivityEnabled()
				|| $this->eventRepository->hasEventOfType(EventTable::SCOPE_DOCUMENT, $documentId, self::EVENT_TYPE_CREATED)
			)
			{
				return;
			}

			$createdBy = (int)($ownership['createdBy'] ?? 0);
			$createdAt = $ownership['createdAt'] ?? null;
			if ($createdBy <= 0 || !($createdAt instanceof DateTime))
			{
				return;
			}

			($this->eventLogService ??= new EventLogService())->recordHistorical(
				EventTable::SCOPE_DOCUMENT,
				$documentId,
				self::EVENT_TYPE_CREATED,
				$createdBy,
				$createdAt,
			);
		}
		catch (\Throwable)
		{
			// Best-effort backfill: never let a history-registration failure break the read.
		}
	}

	/**
	 * [P2.T3] actor + co-authors resolved batched for the whole page (one query
	 * for users, one for co-author rows), plus a batched versionAvailable check.
	 *
	 * @param Event[] $events
	 * @return array<int, array>
	 */
	private function enrich(array $events): array
	{
		if (empty($events))
		{
			return [];
		}

		$eventIds = array_map(static fn(Event $event): int => (int)$event->getId(), $events);
		$authorIdsByEvent = $this->eventAuthorRepository->getAuthorIdsByEventIds($eventIds);

		$userIds = array_map(static fn(Event $event): int => $event->getUserId(), $events);
		foreach ($authorIdsByEvent as $ids)
		{
			array_push($userIds, ...$ids);
		}
		$resolvedUsers = $this->authorResolver->resolve($userIds);

		$versionIds = [];
		foreach ($events as $event)
		{
			if ($event->getEventType() === self::EVENT_TYPE_CONTENT_CHANGED && $event->getVersionId() !== null)
			{
				$versionIds[] = $event->getVersionId();
			}
		}
		$existingVersionIds = empty($versionIds)
			? []
			: array_flip($this->versionRepository->existingIds($versionIds))
		;

		$result = [];
		foreach ($events as $event)
		{
			$eventId = (int)$event->getId();
			$versionId = $event->getVersionId();

			$item = [
				'id' => $eventId,
				'type' => $event->getEventType(),
				'actor' => $resolvedUsers[$event->getUserId()] ?? $this->fallbackActor($event->getUserId()),
				'createdAt' => $event->getCreatedAt()->format('c'),
				'versionId' => $versionId,
				'versionAvailable' => $versionId !== null && isset($existingVersionIds[$versionId]),
			];

			if ($event->getEventType() === self::EVENT_TYPE_CONTENT_CHANGED)
			{
				$authorIds = $authorIdsByEvent[$eventId] ?? [];
				$item['authors'] = array_values(array_map(
					fn(int $id): array => $resolvedUsers[$id] ?? $this->fallbackActor($id),
					$authorIds,
				));
			}

			$result[] = $item;
		}

		return $result;
	}

	/**
	 * Defensive fallback only — AuthorResolver::resolve() is expected to return an
	 * entry for every id it is given, so this branch should not normally trigger.
	 *
	 * @return array{id: int, name: string, avatar: null, color: string}
	 */
	private function fallbackActor(int $id): array
	{
		return ['id' => $id, 'name' => '', 'avatar' => null, 'color' => IdentityColor::forUser($id)];
	}
}

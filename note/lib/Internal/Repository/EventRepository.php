<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Entity\History\Event;
use Bitrix\Note\Internal\Model\EventTable;

class EventRepository
{
	/**
	 * @throws PersistenceException
	 */
	public function save(Event $event): int
	{
		$result = EventTable::add([
			'SCOPE' => $event->getScope(),
			'ENTITY_ID' => $event->getEntityId(),
			'EVENT_TYPE' => $event->getEventType(),
			'USER_ID' => $event->getUserId(),
			'VERSION_ID' => $event->getVersionId(),
			'CREATED_AT' => $event->getCreatedAt(),
		]);

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to save event: ' . implode(', ', $result->getErrorMessages()),
			);
		}

		return (int)$result->getId();
	}

	/**
	 * @param int[] $documentIds
	 * @return int[] ids of SCOPE=document events for the given document ids
	 */
	public function getIdsByDocumentIds(array $documentIds): array
	{
		$normalized = self::normalizeIds($documentIds);
		if (empty($normalized))
		{
			return [];
		}

		$rows = EventTable::query()
			->setSelect(['ID'])
			->where('SCOPE', EventTable::SCOPE_DOCUMENT)
			->whereIn('ENTITY_ID', $normalized)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * [P2.T2] Keyset page of events for one entity, newest first — ordered by
	 * (CREATED_AT DESC, ID DESC) via IX_NOTE_EVENT_FEED_TS so a backfilled event
	 * (old CREATED_AT, new ID) lands at its historical position, not on top.
	 * $eventTypes is the already role+type-filtered allowlist (see FeedProvider);
	 * an empty list means "nothing is visible", not "no filter".
	 *
	 * Cursor: the (CREATED_AT, ID) pair of the last row of the previous page. A
	 * legacy id-only cursor (no createdAt) degrades to a plain ID keyset — still
	 * makes forward progress, only its ordering against backfilled rows is loose.
	 *
	 * @param string[] $eventTypes
	 * @return Event[]
	 */
	public function listByEntity(
		string $scope,
		int $entityId,
		array $eventTypes,
		?DateTime $afterCreatedAt,
		?int $afterId,
		int $limit,
	): array
	{
		if ($entityId <= 0 || $limit <= 0 || empty($eventTypes))
		{
			return [];
		}

		$query = EventTable::query()
			->setSelect(['ID', 'SCOPE', 'ENTITY_ID', 'EVENT_TYPE', 'USER_ID', 'VERSION_ID', 'CREATED_AT'])
			->where('SCOPE', $scope)
			->where('ENTITY_ID', $entityId)
			->whereIn('EVENT_TYPE', array_values(array_unique($eventTypes)))
			->addOrder('CREATED_AT', 'DESC')
			->addOrder('ID', 'DESC')
			->setLimit($limit)
		;

		if ($afterId !== null && $afterId > 0)
		{
			if ($afterCreatedAt !== null)
			{
				$query->where(Query::filter()->logic('or')
					->where('CREATED_AT', '<', $afterCreatedAt)
					->where(Query::filter()
						->where('CREATED_AT', $afterCreatedAt)
						->where('ID', '<', $afterId)
					)
				);
			}
			else
			{
				$query->where('ID', '<', $afterId);
			}
		}

		$rows = $query->fetchAll();

		return array_map(self::hydrate(...), $rows);
	}

	/**
	 * Point exists-check for the lazy 'created' backfill (FeedProvider): does this
	 * entity already have an event of the given type? Reads via IX_NOTE_EVENT_TYPE.
	 */
	public function hasEventOfType(string $scope, int $entityId, string $type): bool
	{
		if ($entityId <= 0 || $type === '')
		{
			return false;
		}

		$row = EventTable::query()
			->setSelect(['ID'])
			->where('SCOPE', $scope)
			->where('ENTITY_ID', $entityId)
			->where('EVENT_TYPE', $type)
			->setLimit(1)
			->fetch()
		;

		return $row !== false;
	}

	/**
	 * [P3.T1] Version id of the newest event of the given type, wrapped so a missing event ("no such
	 * event") is distinguishable from an event that carries no version (VERSION_ID is NULL). Used by
	 * compaction to reconstruct the last settled state: clearing a document records an event without a
	 * version, and the version table alone would still show the text from before the clearing.
	 *
	 * [M4] Served by IX_NOTE_EVENT_TYPE (SCOPE, ENTITY_ID, EVENT_TYPE, ID): the three equality columns
	 * leave the scan ordered by ID, so the newest row of the type is the first one read. Without
	 * EVENT_TYPE in the index this walked the document's whole feed backwards on every compaction.
	 *
	 * @return array{found: bool, versionId: int|null}
	 */
	public function getLatestVersionIdOfType(string $scope, int $entityId, string $type): array
	{
		if ($entityId <= 0 || $type === '')
		{
			return ['found' => false, 'versionId' => null];
		}

		$row = EventTable::query()
			->setSelect(['VERSION_ID'])
			->where('SCOPE', $scope)
			->where('ENTITY_ID', $entityId)
			->where('EVENT_TYPE', $type)
			->addOrder('ID', 'DESC')
			->setLimit(1)
			->fetch()
		;
		if ($row === false)
		{
			return ['found' => false, 'versionId' => null];
		}

		return [
			'found' => true,
			'versionId' => $row['VERSION_ID'] === null ? null : (int)$row['VERSION_ID'],
		];
	}

	/**
	 * [P7.T1 / ALG-03] Keyset batch for the notification drainer — strictly
	 * ID > $lastId, ordered ID ASC, filtered to the notifiable-type allowlist
	 * IN THE QUERY (not a post-filter in memory). Mirrors listByEntity's keyset
	 * shape but walks forward instead of backward.
	 *
	 * $createdAfter is an optional lower freshness bound (CREATED_AT >= threshold),
	 * applied SQL-side: it lets the drainer skip stale/backfilled events (old date,
	 * new ID) without stranding them behind the cursor — see NotificationDrainAgent.
	 *
	 * @param string[] $eventTypes
	 * @return Event[]
	 */
	public function listAfterId(int $lastId, array $eventTypes, int $limit, ?DateTime $createdAfter = null): array
	{
		if ($limit <= 0 || empty($eventTypes))
		{
			return [];
		}

		$query = EventTable::query()
			->setSelect(['ID', 'SCOPE', 'ENTITY_ID', 'EVENT_TYPE', 'USER_ID', 'VERSION_ID', 'CREATED_AT'])
			->where('ID', '>', max(0, $lastId))
			->whereIn('EVENT_TYPE', array_values(array_unique($eventTypes)))
			->addOrder('ID', 'ASC')
			->setLimit($limit)
		;

		if ($createdAfter !== null)
		{
			$query->where('CREATED_AT', '>=', $createdAfter);
		}

		return array_map(self::hydrate(...), $query->fetchAll());
	}

	private static function hydrate(array $row): Event
	{
		$createdAt = $row['CREATED_AT'] ?? null;
		if (!($createdAt instanceof DateTime))
		{
			$createdAt = $createdAt ? DateTime::createFromUserTime((string)$createdAt) : new DateTime();
		}

		return new Event(
			(int)$row['ID'],
			(string)$row['SCOPE'],
			(int)$row['ENTITY_ID'],
			(string)$row['EVENT_TYPE'],
			(int)$row['USER_ID'],
			isset($row['VERSION_ID']) ? (int)$row['VERSION_ID'] : null,
			$createdAt,
		);
	}

	/**
	 * @param int[] $documentIds
	 */
	public function deleteByDocumentIds(array $documentIds): void
	{
		$normalized = self::normalizeIds($documentIds);
		if (empty($normalized))
		{
			return;
		}

		EventTable::deleteByFilter([
			'=SCOPE' => EventTable::SCOPE_DOCUMENT,
			'=ENTITY_ID' => $normalized,
		]);
	}

	/**
	 * @return int[]
	 */
	private static function normalizeIds(array $ids): array
	{
		return array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
	}
}

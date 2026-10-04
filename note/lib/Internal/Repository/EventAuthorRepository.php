<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Note\Internal\Model\EventAuthorTable;

class EventAuthorRepository
{
	/**
	 * Bulk-inserts co-authors tied to a content_changed event. Authors are attributed to the
	 * EVENT (not the version) so they survive the version snapshot's TTL cleanup.
	 *
	 * @param int[] $authorIds
	 */
	public function saveAuthors(int $eventId, array $authorIds): void
	{
		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $authorIds),
			static fn(int $id): bool => $id > 0,
		)));
		if ($eventId <= 0 || empty($normalized))
		{
			return;
		}

		$rows = [];
		foreach ($normalized as $userId)
		{
			$rows[] = sprintf('(%d, %d)', $eventId, $userId);
		}

		Application::getConnection()->queryExecute(
			'INSERT INTO b_note_event_author (EVENT_ID, USER_ID) VALUES ' . implode(', ', $rows),
		);
	}

	/**
	 * [P2.T3] Batch read for feed enrichment — one query per feed page instead of
	 * one per event, so the client gets the co-author stack without a second request.
	 *
	 * @param int[] $eventIds
	 * @return array<int, int[]> eventId => co-author userIds
	 */
	public function getAuthorIdsByEventIds(array $eventIds): array
	{
		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $eventIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return [];
		}

		$rows = EventAuthorTable::query()
			->setSelect(['EVENT_ID', 'USER_ID'])
			->whereIn('EVENT_ID', $normalized)
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['EVENT_ID']][] = (int)$row['USER_ID'];
		}

		return $result;
	}

	/**
	 * @param int[] $eventIds
	 */
	public function deleteByEventIds(array $eventIds): void
	{
		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $eventIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return;
		}

		EventAuthorTable::deleteByFilter(['=EVENT_ID' => $normalized]);
	}
}

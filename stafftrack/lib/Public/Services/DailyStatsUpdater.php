<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Internal\Entity\CheckInType;
use Bitrix\StaffTrack\Internal\Integration\Disk\FileResolver;
use Bitrix\StaffTrack\Internal\Model\CheckInDailyStatsTable;
use Bitrix\StaffTrack\Internal\Model\CheckInTable;
use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;
use Bitrix\StaffTrack\Public\Provider\CheckInMarkerNormalizer;

class DailyStatsUpdater
{
	private const DESCRIPTION_ENTER = 'ENTER';
	private const DESCRIPTION_LEAVE = 'LEAVE';
	private const DAY_LENGTH_SEC = 86399;
	private const MAX_TIMEZONE_OFFSET = 50400;

	private CheckInRepository $repository;
	private CheckInMarkerAggregator $aggregator;

	public function __construct()
	{
		$this->repository = new CheckInRepository();
		$this->aggregator = new CheckInMarkerAggregator();
	}

	/**
	 * Writes the daily stats of a single check-in and builds the delta for its add push.
	 *
	 * The two are separate phases ({@see applyAdd}, {@see buildAddDelta}): a caller that owns a
	 * write transaction runs them around its commit, so that only the write is held by the
	 * transaction. This method keeps both for callers without one.
	 *
	 * @return array{userId: int, cnt: int, lastMarker: ?array, isTechnical: bool, point: ?array{lat: float, lon: float, time: int}}
	 */
	public function update(int $userId, int $checkInId, int $entityType, string $description, int $userTimezone, int $timestamp): array
	{
		$addState = $this->applyAdd($userId, $checkInId, $entityType, $description, $userTimezone, $timestamp);

		if (!Feature::isCheckInRolloutEnabled($userId))
		{
			return [];
		}

		return $this->buildAddDelta($addState);
	}

	/**
	 * Applies the daily-stats write of a single check-in.
	 *
	 * This is the whole write of the add path and the only part that belongs inside the caller's
	 * transaction; the returned state is the handoff to buildAddDelta().
	 *
	 * @return array{userId: int, checkInId: int, entityType: int, description: string, userTimezone: int, localDate: Date, clientCnt: int}
	 */
	public function applyAdd(int $userId, int $checkInId, int $entityType, string $description, int $userTimezone, int $timestamp): array
	{
		$localDate = $this->getLocalDate($timestamp, $userTimezone);
		$stats = $this->findStats($userId, $localDate) ?? $this->createStats($userId, $localDate);

		$hasOpenEnter = (bool)($stats['HAS_OPEN_ENTER'] ?? false);
		[$cntDelta, $newHasOpenEnter] = $this->calculateDelta($entityType, $description, $hasOpenEnter);

		$updateFields = [
			'CNT' => (int)($stats['CNT'] ?? 0) + $cntDelta,
			'HAS_OPEN_ENTER' => $newHasOpenEnter ? 1 : 0,
		];

		$isClosingLeave =
			$entityType !== CheckInType::MANUAL->value
			&& $description === self::DESCRIPTION_LEAVE
			&& $hasOpenEnter;

		if (!$isClosingLeave && $this->isNewerThanLast((int)($stats['LAST_CHECK_IN_ID'] ?? 0), $timestamp + $userTimezone))
		{
			$updateFields['LAST_CHECK_IN_ID'] = $checkInId;
		}

		CheckInDailyStatsTable::update($stats['ID'], $updateFields);

		return [
			'userId' => $userId,
			'checkInId' => $checkInId,
			'entityType' => $entityType,
			'description' => $description,
			'userTimezone' => $userTimezone,
			'localDate' => $localDate,
			// The push delta feeds the same client counter as the read path, so it must use the read
			// scale (CheckInProvider stats), not the marker count: the two scales do not match.
			'clientCnt' => $updateFields['CNT'] + (int)$newHasOpenEnter,
		];
	}

	/**
	 * Builds the add-push delta for a check-in whose stats write is already committed.
	 *
	 * Rereads and reaggregates the whole local day, so it must stay outside the write transaction.
	 *
	 * @param array{userId: int, checkInId: int, entityType: int, description: string, userTimezone: int, localDate: Date, clientCnt: int} $addState
	 * @return array{userId: int, cnt: int, lastMarker: ?array, isTechnical: bool, point: ?array{lat: float, lon: float, time: int}}
	 */
	public function buildAddDelta(array $addState): array
	{
		$rows = $this->readDayCheckInRows($addState['userId'], $addState['localDate']);
		$events = $this->normalizeEvents($rows);

		$isTechnical = $this->isTechnicalAddition(
			$addState['checkInId'],
			$addState['entityType'],
			$addState['description'],
			$events,
		);

		return [
			'userId' => $addState['userId'],
			'cnt' => $addState['clientCnt'],
			'lastMarker' => $isTechnical
				? null
				: $this->buildLastMarker(
					$rows,
					$events,
					$addState['checkInId'],
					$addState['userId'],
					$addState['userTimezone'],
				),
			'isTechnical' => $isTechnical,
			'point' => $this->buildDeltaPoint($addState['checkInId'], $events),
		];
	}

	/**
	 * @param array<int, array> $rows
	 * @param array<int, array> $events
	 */
	private function buildLastMarker(array $rows, array $events, int $checkInId, int $userId, int $userTimezone): ?array
	{
		$markers = $this->aggregator->buildMarkers($events, null);
		if (empty($markers))
		{
			return null;
		}

		$rawRowsById = [];
		foreach ($rows as $row)
		{
			$rawRowsById[(int)($row['ID'] ?? 0)] = $row;
		}

		$marker = $this->findMarkerOfCheckIn($markers, $checkInId) ?? $this->findLastFinishedMarker($markers);

		return CheckInMarkerNormalizer::normalize(
			$marker,
			$rawRowsById,
			$this->resolveMarkerFiles($marker, $rawRowsById, $userId),
			$userTimezone,
		);
	}

	/**
	 * The push is about the check-in that has just been written, so its own marker wins.
	 *
	 * @param array<int, array> $markers
	 */
	private function findMarkerOfCheckIn(array $markers, int $checkInId): ?array
	{
		foreach ($markers as $marker)
		{
			$isMarkerOfCheckIn =
				(int)($marker['event']['id'] ?? 0) === $checkInId
				|| (int)($marker['auto']['id'] ?? 0) === $checkInId;

			if ($isMarkerOfCheckIn)
			{
				return $marker;
			}
		}

		return null;
	}

	/**
	 * A closing LEAVE has no marker of its own, it only ends a stay. Markers are ordered by their
	 * start, and a stay is no longer broken by a manual check-in inside it, so the just closed stay
	 * can start earlier than that manual and still be the last thing to end.
	 *
	 * @param array<int, array> $markers
	 */
	private function findLastFinishedMarker(array $markers): array
	{
		$last = $markers[0];
		foreach ($markers as $marker)
		{
			if ($this->markerEnd($marker) >= $this->markerEnd($last))
			{
				$last = $marker;
			}
		}

		return $last;
	}

	private function markerEnd(array $marker): int
	{
		return (int)($marker['endTimestamp'] ?? $marker['timestamp']);
	}

	/**
	 * @param array<int, array> $events
	 */
	private function isTechnicalAddition(int $checkInId, int $entityType, string $description, array $events): bool
	{
		if ($entityType === CheckInType::MANUAL->value)
		{
			return false;
		}

		if ($description === self::DESCRIPTION_ENTER)
		{
			return false;
		}

		if ($description === self::DESCRIPTION_LEAVE)
		{
			$priorEvents = array_values(
				array_filter($events, static fn (array $event): bool => (int)$event['id'] !== $checkInId),
			);

			return !$this->hasOpenEnter($priorEvents);
		}

		return true;
	}

	/**
	 * @param array<int, array> $events
	 * @return ?array{lat: float, lon: float, time: int}
	 */
	private function buildDeltaPoint(int $checkInId, array $events): ?array
	{
		foreach ($events as $event)
		{
			if ((int)$event['id'] !== $checkInId)
			{
				continue;
			}

			if ($event['latitude'] === null || $event['longitude'] === null)
			{
				return null;
			}

			return [
				'lat' => (float)$event['latitude'],
				'lon' => (float)$event['longitude'],
				'time' => (int)$event['timestamp'],
			];
		}

		return null;
	}

	/**
	 * @param array{kind: string, event: array, duration: ?int, auto: ?array, timestamp: int} $marker
	 * @param array<int, array> $rawRowsById
	 * @return array
	 */
	private function resolveMarkerFiles(array $marker, array $rawRowsById, int $userId): array
	{
		if ($marker['kind'] === CheckInMarkerAggregator::KIND_STAY)
		{
			return [];
		}

		$leadingId = (int)($marker['event']['id'] ?? 0);
		$row = $rawRowsById[$leadingId] ?? null;
		if ($row === null)
		{
			return [];
		}

		$fileIds = is_array($row['FILE_IDS'] ?? null) ? $row['FILE_IDS'] : [];
		if (empty($fileIds))
		{
			return [];
		}

		return (new FileResolver())->resolve($leadingId, $fileIds, $userId);
	}

	/**
	 * Batch daily-stats recompute for a set of a single user's check-in points.
	 *
	 * Existing daily stats are read once per local-date group and written with a single
	 * update/add per group (no per-point SELECT). The HAS_OPEN_ENTER invariant requires
	 * chronological processing, so points inside each group are sorted by local time first.
	 *
	 * This is the write phase of the batch path; the returned state is the handoff to
	 * {@see buildBatchDelta()}, the same two-phase split the single add path uses.
	 *
	 * @param int $userId
	 * @param array<int, array{id: int, entityType: int, description: string, timestamp: int, latitude?: float, longitude?: float}> $points
	 * @param int $userTimezone
	 * @return array{userId: int, userTimezone: int, localDate: ?Date, clientCnt: int, markerCheckInId: int, points: array<int, array{lat: float, lon: float, time: int}>}
	 */
	public function updateBatch(int $userId, array $points, int $userTimezone): array
	{
		$batchState = [
			'userId' => $userId,
			'userTimezone' => $userTimezone,
			'localDate' => null,
			'clientCnt' => 0,
			'markerCheckInId' => 0,
			'points' => [],
		];

		if (empty($points))
		{
			return $batchState;
		}

		$groups = [];
		foreach ($points as $point)
		{
			$localDate = $this->getLocalDate((int)($point['timestamp'] ?? 0), $userTimezone);
			$key = $localDate->format('Y-m-d');
			if (!isset($groups[$key]))
			{
				$groups[$key] = [
					'localDate' => $localDate,
					'points' => [],
				];
			}

			$groups[$key]['points'][] = $point;
		}

		// The push delta describes one local day, so a batch that crosses midnight reports its latest
		// one: that is the day the receiving map has on screen.
		ksort($groups);
		$latestKey = array_key_last($groups);

		foreach ($groups as $key => $group)
		{
			$groupPoints = $group['points'];
			usort(
				$groupPoints,
				static function (array $a, array $b) use ($userTimezone): int {
					$tsA = (int)($a['timestamp'] ?? 0) + $userTimezone;
					$tsB = (int)($b['timestamp'] ?? 0) + $userTimezone;

					return $tsA <=> $tsB ?: (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
				},
			);

			$stats = $this->findStats($userId, $group['localDate']) ?? $this->createStats($userId, $group['localDate']);

			$cnt = (int)($stats['CNT'] ?? 0);
			$hasOpenEnter = (bool)($stats['HAS_OPEN_ENTER'] ?? false);
			$lastId = (int)($stats['LAST_CHECK_IN_ID'] ?? 0);
			$lastLocalTimestamp = $lastId > 0 ? ($this->getCheckInLocalTimestamp($lastId) ?? 0) : 0;
			$isLatestGroup = $key === $latestKey;

			foreach ($groupPoints as $point)
			{
				$entityType = (int)($point['entityType'] ?? 0);
				$description = (string)($point['description'] ?? '');

				if ($isLatestGroup && $this->producesMarker($entityType, $description, $hasOpenEnter))
				{
					$batchState['markerCheckInId'] = (int)($point['id'] ?? 0);
				}

				[$cntDelta, $newHasOpenEnter] = $this->calculateDelta($entityType, $description, $hasOpenEnter);
				$cnt += $cntDelta;

				$isClosingLeave =
					$entityType !== CheckInType::MANUAL->value
					&& $description === self::DESCRIPTION_LEAVE
					&& $hasOpenEnter;

				$pointLocalTimestamp = (int)($point['timestamp'] ?? 0) + $userTimezone;
				if (!$isClosingLeave && $pointLocalTimestamp > $lastLocalTimestamp)
				{
					$lastId = (int)($point['id'] ?? 0);
					$lastLocalTimestamp = $pointLocalTimestamp;
				}

				$hasOpenEnter = $newHasOpenEnter;
			}

			CheckInDailyStatsTable::update($stats['ID'], [
				'CNT' => $cnt,
				'HAS_OPEN_ENTER' => $hasOpenEnter ? 1 : 0,
				'LAST_CHECK_IN_ID' => $lastId,
			]);

			if ($isLatestGroup)
			{
				$batchState['localDate'] = $group['localDate'];
				// Same read scale as applyAdd(): CNT + HAS_OPEN_ENTER is the number every client shows.
				$batchState['clientCnt'] = $cnt + (int)$hasOpenEnter;
				$batchState['points'] = $this->extractDeltaPoints($groupPoints);
			}
		}

		return $batchState;
	}

	/**
	 * Builds the batch-push delta for points whose stats write is already committed.
	 *
	 * The counter, the technical flag and the track points all come out of the write walk, so the
	 * common batch - movement points only - costs no query at all. Only a batch that really produced
	 * a marker (an ENTER, or a LEAVE that closed a stay) pays the day reread, the same rule the single
	 * add path follows for a technical point.
	 *
	 * @param array{userId: int, userTimezone: int, localDate: ?Date, clientCnt: int, markerCheckInId: int, points: array<int, array{lat: float, lon: float, time: int}>} $batchState
	 * @return array{userId: int, cnt: int, lastMarker: ?array, isTechnical: bool, points: array<int, array{lat: float, lon: float, time: int}>, localDate: ?string}
	 */
	public function buildBatchDelta(array $batchState): array
	{
		$markerCheckInId = (int)$batchState['markerCheckInId'];
		$isTechnical = $markerCheckInId === 0;

		return [
			'userId' => $batchState['userId'],
			'cnt' => $batchState['clientCnt'],
			'lastMarker' => $isTechnical ? null : $this->readDayAndBuildLastMarker($batchState, $markerCheckInId),
			'isTechnical' => $isTechnical,
			'points' => $batchState['points'],
			// The queue of auto points lives on the device and survives an offline night, so a batch sent
			// in the morning can still count yesterday. The delta states the local day it belongs to - the
			// same date the daily stats are keyed by - and the client applies it only to the day it shows.
			'localDate' => $batchState['localDate'] instanceof Date
				? $batchState['localDate']->format('Y-m-d')
				: null,
		];
	}

	/**
	 * @param array{userId: int, userTimezone: int, localDate: ?Date} $batchState
	 */
	private function readDayAndBuildLastMarker(array $batchState, int $markerCheckInId): ?array
	{
		if (!($batchState['localDate'] instanceof Date))
		{
			return null;
		}

		$rows = $this->readDayCheckInRows($batchState['userId'], $batchState['localDate']);

		return $this->buildLastMarker(
			$rows,
			$this->normalizeEvents($rows),
			$markerCheckInId,
			$batchState['userId'],
			$batchState['userTimezone'],
		);
	}

	/**
	 * Whether a point becomes a marker of {@see CheckInMarkerAggregator} - the same rule
	 * {@see isTechnicalAddition()} states for the single add path, but read off the running
	 * HAS_OPEN_ENTER instead of a reread of the day.
	 */
	private function producesMarker(int $entityType, string $description, bool $hasOpenEnter): bool
	{
		if ($entityType === CheckInType::MANUAL->value)
		{
			return true;
		}

		if ($description === self::DESCRIPTION_ENTER)
		{
			return true;
		}

		if ($description === self::DESCRIPTION_LEAVE)
		{
			return $hasOpenEnter;
		}

		return false;
	}

	/**
	 * @param array<int, array{id: int, timestamp: int, latitude?: ?float, longitude?: ?float}> $groupPoints
	 * @return array<int, array{lat: float, lon: float, time: int}>
	 */
	private function extractDeltaPoints(array $groupPoints): array
	{
		$deltaPoints = [];
		foreach ($groupPoints as $point)
		{
			if (!isset($point['latitude'], $point['longitude']))
			{
				continue;
			}

			$deltaPoints[] = [
				'lat' => (float)$point['latitude'],
				'lon' => (float)$point['longitude'],
				'time' => (int)($point['timestamp'] ?? 0),
			];
		}

		return $deltaPoints;
	}

	/**
	 * Shifts the stored CNT by one check-in without rereading the day.
	 *
	 * CNT + HAS_OPEN_ENTER is the number every client shows, so the delta follows the scale the map
	 * and the timeline read: one event per marker of {@see CheckInMarkerAggregator} - manual, auto
	 * stay, merged. Technical auto points (MOVING_POINT, a LEAVE with no open stay) produce no marker
	 * and must not move the counter. A stay is counted once, when its ENTER is closed; while it is
	 * open it is carried by HAS_OPEN_ENTER instead.
	 *
	 * The one thing a per-point delta cannot see is the manual-with-stay merge: it needs the whole day
	 * and distances between its points. A manual check-in that the aggregator later merges into a
	 * nearby stay is counted twice here - the accepted price for keeping the write O(1) (the client
	 * posts every auto point separately).
	 *
	 * @return array{0: int, 1: bool}
	 */
	private function calculateDelta(int $entityType, string $description, bool $hasOpenEnter): array
	{
		if ($entityType === CheckInType::MANUAL->value)
		{
			// A manual check-in is a marker of its own and never closes an open stay.
			return [1, $hasOpenEnter];
		}

		if ($description === self::DESCRIPTION_ENTER)
		{
			// A second ENTER closes the previous stay and opens its own.
			return [$hasOpenEnter ? 1 : 0, true];
		}

		if ($description === self::DESCRIPTION_LEAVE)
		{
			return [$hasOpenEnter ? 1 : 0, false];
		}

		return [0, $hasOpenEnter];
	}

	private function isNewerThanLast(int $lastCheckInId, int $localTimestamp): bool
	{
		if ($lastCheckInId <= 0)
		{
			return true;
		}

		$lastLocalTimestamp = $this->getCheckInLocalTimestamp($lastCheckInId);
		if ($lastLocalTimestamp === null)
		{
			return true;
		}

		return $localTimestamp > $lastLocalTimestamp;
	}

	private function getCheckInLocalTimestamp(int $checkInId): ?int
	{
		$row = CheckInTable::query()
			->setSelect(['DATE_CREATE', 'USER_TIMEZONE'])
			->where('ID', $checkInId)
			->setLimit(1)
			->exec()
			->fetch();

		if (!$row || !($row['DATE_CREATE'] instanceof DateTime))
		{
			return null;
		}

		return $row['DATE_CREATE']->getTimestamp() + (int)($row['USER_TIMEZONE'] ?? 0);
	}

	public function backFillForUser(int $userId, array $checkInRows, Date $localDate): void
	{
		if ($this->findStats($userId, $localDate) !== null)
		{
			return;
		}

		$cnt = 0;
		$hasOpenEnter = false;
		$lastCheckInId = 0;

		foreach ($checkInRows as $row)
		{
			$entityType = (int)($row['ENTITY_TYPE'] ?? 0);
			$description = (string)($row['DESCRIPTION'] ?? '');
			[$cntDelta, $newHasOpenEnter] = $this->calculateDelta($entityType, $description, $hasOpenEnter);
			$cnt += $cntDelta;

			$isClosingLeave =
				$entityType !== CheckInType::MANUAL->value
				&& $description === self::DESCRIPTION_LEAVE
				&& $hasOpenEnter;

			if (!$isClosingLeave)
			{
				$lastCheckInId = (int)($row['ID'] ?? 0);
			}

			$hasOpenEnter = $newHasOpenEnter;
		}

		CheckInDailyStatsTable::add([
			'USER_ID' => $userId,
			'LOCAL_DATE' => $localDate,
			'CNT' => $cnt,
			'HAS_OPEN_ENTER' => $hasOpenEnter ? 1 : 0,
			'LAST_CHECK_IN_ID' => $lastCheckInId,
		]);
	}

	private function findStats(int $userId, Date $localDate): ?array
	{
		$row = CheckInDailyStatsTable::query()
			->setSelect(['ID', 'CNT', 'HAS_OPEN_ENTER', 'LAST_CHECK_IN_ID'])
			->where('USER_ID', $userId)
			->where('LOCAL_DATE', $localDate)
			->setLimit(1)
			->exec()
			->fetch();

		return $row ?: null;
	}

	private function createStats(int $userId, Date $localDate): array
	{
		$result = CheckInDailyStatsTable::add([
			'USER_ID' => $userId,
			'LOCAL_DATE' => $localDate,
			'CNT' => 0,
			'HAS_OPEN_ENTER' => 0,
			'LAST_CHECK_IN_ID' => 0,
		]);

		// On a unique-index race (USER_ID + LOCAL_DATE) add() returns a failed Result with a null id.
		// Fail in a controlled way instead of feeding null into update(): the save paths catch every
		// throwable and roll the whole transaction back into a Result-error.
		if (!$result->isSuccess())
		{
			throw new SystemException('Failed to create daily stats row');
		}

		return [
			'ID' => $result->getId(),
			'CNT' => 0,
			'HAS_OPEN_ENTER' => 0,
		];
	}

	private function getLocalDate(int $timestamp, int $userTimezone): Date
	{
		$localDateString = date('Y-m-d', $timestamp + $userTimezone);

		return new Date($localDateString, 'Y-m-d');
	}

	/**
	 * @return array<int, array>
	 */
	private function readDayCheckInRows(int $userId, Date $localDate): array
	{
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($localDate);

		$hotRows = $this->repository->getAllUsersCheckInByPeriod([$userId], $dateStart, $dateEnd);
		$archivedRows = $this->repository->getAllUsersAggregatedPointsByPeriod([$userId], $dateStart, $dateEnd);

		$localDateString = (string)$localDate->format('Y-m-d');

		return array_values(array_filter(
			array_merge($hotRows, $archivedRows),
			fn (array $row): bool => $this->isCheckInOnLocalDate($row, $localDateString),
		));
	}

	/**
	 * @return array{0: DateTime, 1: DateTime}
	 */
	private function getWideDayBoundaries(Date $localDate): array
	{
		$midnightUtc = strtotime($localDate->format('Y-m-d') . ' 00:00:00 UTC');

		return [
			DateTime::createFromTimestamp($midnightUtc - self::MAX_TIMEZONE_OFFSET),
			DateTime::createFromTimestamp($midnightUtc + self::DAY_LENGTH_SEC + self::MAX_TIMEZONE_OFFSET),
		];
	}

	private function isCheckInOnLocalDate(array $row, string $localDateString): bool
	{
		$timestamp = $this->extractTimestamp($row['DATE_CREATE'] ?? null);
		$userTimezone = (int)($row['USER_TIMEZONE'] ?? 0);

		return date('Y-m-d', $timestamp + $userTimezone) === $localDateString;
	}

	/**
	 * @return array<int, array{id: int, entityType: int, description: string, latitude: float, longitude: float, address: string, timestamp: int, userTimezone: int}>
	 */
	private function normalizeEvents(array $rows): array
	{
		$events = [];
		foreach ($rows as $row)
		{
			$events[] = [
				'id' => (int)($row['ID'] ?? 0),
				'entityType' => (int)($row['ENTITY_TYPE'] ?? 0),
				'description' => (string)($row['DESCRIPTION'] ?? ''),
				'latitude' => $row['LATITUDE'] !== null ? (float)$row['LATITUDE'] : null,
				'longitude' => $row['LONGITUDE'] !== null ? (float)$row['LONGITUDE'] : null,
				'address' => (string)($row['ADDRESS'] ?? ''),
				'timestamp' => $this->extractTimestamp($row['DATE_CREATE'] ?? null),
				'userTimezone' => (int)($row['USER_TIMEZONE'] ?? 0),
			];
		}

		usort($events, static fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp'] ?: $a['id'] <=> $b['id']);

		return $events;
	}

	private function extractTimestamp(mixed $value): int
	{
		if ($value instanceof DateTime)
		{
			return $value->getTimestamp();
		}

		if (is_int($value))
		{
			return $value;
		}

		return 0;
	}

	/**
	 * Only a real LEAVE closes a stay: manual check-ins and technical auto points (MOVING_POINT and
	 * the like) leave it open, the same model as CheckInMarkerAggregator::collapseAutoCheckIns().
	 */
	private function hasOpenEnter(array $events): bool
	{
		$openEnter = false;
		foreach ($events as $event)
		{
			if ($event['entityType'] === CheckInType::MANUAL->value)
			{
				continue;
			}

			if ($event['description'] === self::DESCRIPTION_ENTER)
			{
				$openEnter = true;
			}
			elseif ($event['description'] === self::DESCRIPTION_LEAVE)
			{
				$openEnter = false;
			}
		}

		return $openEnter;
	}
}

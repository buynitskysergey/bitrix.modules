<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Internal\Integration\Disk\FileResolver;
use Bitrix\StaffTrack\Internal\Integration\Pull;
use Bitrix\StaffTrack\Internal\Integration\Timeman\WorkDayService;
use Bitrix\StaffTrack\Internal\Model\CheckInDailyStatsTable;
use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;
use Bitrix\StaffTrack\Public\Services\CheckInMarkerAggregator;
use Bitrix\StaffTrack\Public\Services\DailyStatsUpdater;
use Bitrix\StaffTrack\Public\Services\FirstManualCheckInCache;
use Bitrix\StaffTrack\Public\Services\MapDataCache;

class CheckInProvider
{
	private const DAY_END_OFFSET = 86399;
	private const MAX_TIMEZONE_OFFSET = 50400;
	private const GAP_THRESHOLD_SEC = 300;
	private const GAP_DISTANCE_METERS = 150;
	private const EARTH_RADIUS_METERS = 6371000;

	private CheckInRepository $repository;

	public function __construct()
	{
		$this->repository = new CheckInRepository();
	}

	private function subscribeToPush(int $userId): void
	{
		Pull\PushSender::subscribeToTag(Pull\Tag::getUserTag($userId));
	}

	/**
	 * @return array{checkIns: array<int, array>, stats: array<int, int>, manualStats: array<int, int>, checkInRolloutEnabled: array<int, bool>}
	 */
	public function getLatestUsersCheckInByDepartment(int $timezoneOffset = 0, int $timestamp = 0): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId <= 0)
		{
			return ['checkIns' => [], 'stats' => [], 'manualStats' => [], 'checkInRolloutEnabled' => []];
		}

		$this->subscribeToPush($currentUserId);

		$subordinateIds = DepartmentProvider::getSubordinateIds($currentUserId);

		if (!empty($subordinateIds))
		{
			$userIds = array_values(array_unique(array_merge([$currentUserId], $subordinateIds)));
		}
		else
		{
			$userIds = [$currentUserId];
		}

		$requestedDate = $this->getRequestedDate($timestamp > 0 ? $timestamp : null, $timezoneOffset);

		if ($this->isPastLocalDate($requestedDate, $timezoneOffset))
		{
			$cached = MapDataCache::get($currentUserId, $requestedDate, $userIds);
			if ($cached !== null)
			{
				return $cached;
			}

			$result = $this->collectLatestCheckInsWithStats($userIds, $timezoneOffset, $timestamp);
			$result['checkInRolloutEnabled'] = Feature::filterCheckInRolloutEnabled($userIds);
			MapDataCache::set($currentUserId, $requestedDate, $userIds, $result);

			return $result;
		}

		$result = $this->collectLatestCheckInsWithStats($userIds, $timezoneOffset, $timestamp);
		$result['checkInRolloutEnabled'] = Feature::filterCheckInRolloutEnabled($userIds);

		return $result;
	}

	private function isPastLocalDate(string $requestedDate, int $timezoneOffset): bool
	{
		$todayLocalDate = date('Y-m-d', time() + $timezoneOffset);

		return $requestedDate < $todayLocalDate;
	}

	/**
	 * @param int $userId
	 * @param int $timestamp
	 * @param int $timezoneOffset
	 * @return array{route: array<int, array>, track: array<int, array{id: int, lat: float, lon: float, time: int, gapBefore: bool, address: string}>, markers: array<int, array>, isDayClosed: bool, stats?: array<int, int>, checkInRolloutEnabled: bool}
	 */
	public function getEmployeeRoute(int $userId, int $timestamp, int $timezoneOffset): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId <= 0)
		{
			return [
				'route' => [],
				'track' => [],
				'markers' => [],
				'isDayClosed' => false,
				'checkInRolloutEnabled' => false,
			];
		}

		$this->subscribeToPush($currentUserId);
		$requestedDate = $this->getRequestedDate($timestamp, $timezoneOffset);
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);

		$checkInCollection = $this->repository->getUserRouteByPeriod($userId, $dateStart, $dateEnd);
		$aggregatedCheckIns = $this->repository->getAggregatedPointsByPeriod($userId, $dateStart, $dateEnd);
		$dayRows = $this->collectDayRows($checkInCollection, $aggregatedCheckIns, $requestedDate);

		$dayCloseTimestamp = WorkDayService::getDayCloseTimestamp($userId, $timestamp, $timezoneOffset);
		$dayResult = $this->buildDayResult($dayRows, $timezoneOffset, $currentUserId, $dayCloseTimestamp);

		$statsByUser = $this->getDailyStatsByUsers([$userId], $timezoneOffset, $timestamp);
		$stats = [];
		foreach ($statsByUser as $statRow)
		{
			$rowUserId = (int)$statRow['USER_ID'];
			$stats[$rowUserId] = (int)$statRow['CNT'] + (int)$statRow['HAS_OPEN_ENTER'];
		}

		return [
			'track' => $dayResult['track'],
			'markers' => $dayResult['markers'],
			'isDayClosed' => $dayCloseTimestamp !== null,
			'stats' => $stats,
			'checkInRolloutEnabled' => Feature::isCheckInRolloutEnabled($userId),
			// the flat list is read by the v2 client and is served unconditionally: the client
			// generation is decided by the bundle on the device, which can lag behind the rollout
			// flag of its viewer, and a missing key draws an empty day with no error
			'route' => self::dropCheckInsWithoutGeo(
				$this->normalizeCheckIns($dayRows, $timezoneOffset, $currentUserId),
			),
		];
	}

	/**
	 * @param int $userId
	 * @param int $timestamp
	 * @param int $timezoneOffset
	 * @return array{checkIns?: array<int, array>, track?: array<int, array{id: int, lat: float, lon: float, time: int, gapBefore: bool, address: string}>, markers?: array<int, array>, isDayClosed?: bool, dayCloseTimestamp?: ?int, checkInRolloutEnabled: bool}
	 */
	public function getUserCheckInByDate(int $userId, int $timestamp, int $timezoneOffset = 0): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId <= 0)
		{
			return ['checkInRolloutEnabled' => false];
		}

		$this->subscribeToPush($currentUserId);
		$requestedDate = $this->getRequestedDate($timestamp, $timezoneOffset);
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);

		$checkInCollection = $this->repository->getUserCheckInByPeriod($userId, $dateStart, $dateEnd);
		$aggregatedCheckIns = $this->repository->getAggregatedPointsByPeriod($userId, $dateStart, $dateEnd);
		$dayRows = $this->collectDayRows($checkInCollection, $aggregatedCheckIns, $requestedDate);

		$dayCloseTimestamp = WorkDayService::getDayCloseTimestamp($userId, $timestamp, $timezoneOffset);
		$dayResult = $this->buildDayResult($dayRows, $timezoneOffset, $currentUserId, $dayCloseTimestamp);

		return [
			'track' => $dayResult['track'],
			'markers' => $dayResult['markers'],
			'isDayClosed' => $dayCloseTimestamp !== null,
			'dayCloseTimestamp' => $dayCloseTimestamp,
			'checkInRolloutEnabled' => Feature::isCheckInRolloutEnabled($userId),
			// the flat list is read by the v2 client and is served unconditionally: the client
			// generation is decided by the bundle on the device, which can lag behind the rollout
			// flag of its viewer, and a missing key draws an empty day with no error
			'checkIns' => self::dropCheckInsWithoutGeo(
				$this->normalizeCheckIns($dayRows, $timezoneOffset, $currentUserId),
			),
		];
	}

	/**
	 * @param int $userId
	 * @param int $timestamp
	 * @param int|null $timezoneOffset
	 * @return int
	 */
	public function getManualCheckInCountByDate(int $userId, int $timestamp, ?int $timezoneOffset = null): int
	{
		$timezoneOffset ??= \CTimeZone::GetOffset();

		$requestedDate = $this->getRequestedDate($timestamp, $timezoneOffset);
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);

		$rows = $this->repository->getUserManualCheckInDatesByPeriod($userId, $dateStart, $dateEnd);

		$count = 0;
		foreach ($rows as $row)
		{
			if ($this->isCheckInOnLocalDate($row, $requestedDate))
			{
				$count++;
			}
		}

		return $count;
	}

	public function isFirstManualCheckInToday(int $userId, ?int $timezoneOffset = null): bool
	{
		$timezoneOffset ??= \CTimeZone::GetOffset();
		$localDate = $this->getRequestedDate(time(), $timezoneOffset);

		$cached = FirstManualCheckInCache::get($userId, $localDate);
		if ($cached !== null)
		{
			return $cached;
		}

		$isFirst = $this->getManualCheckInCountByDate($userId, time(), $timezoneOffset) === 0;
		FirstManualCheckInCache::set($userId, $localDate, $isFirst);

		return $isFirst;
	}

	/**
	 * @param int $userId
	 * @param int $timestamp
	 * @param int|null $timezoneOffset
	 * @return array<int, array>
	 */
	public function getCurrentUserManualCheckInsByDate(int $userId, int $timestamp, ?int $timezoneOffset = null): array
	{
		$timezoneOffset ??= \CTimeZone::GetOffset();

		$requestedDate = $this->getRequestedDate($timestamp, $timezoneOffset);
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);

		$checkInCollection = $this->repository->getUserManualCheckInByPeriod($userId, $dateStart, $dateEnd);
		$aggregatedCheckIns = $this->repository->getUserAggregatedManualPointsByPeriod($userId, $dateStart, $dateEnd);
		$dayRows = $this->collectDayRows($checkInCollection, $aggregatedCheckIns, $requestedDate);

		return $this->normalizeCheckIns($dayRows, $timezoneOffset, $userId);
	}

	/**
	 * @param int[] $userIds
	 * @return array{checkIns: array<int, array>, stats: array<int, int>, manualStats: array<int, int>}
	 */
	private function collectLatestCheckInsWithStats(array $userIds, int $timezoneOffset, int $timestamp): array
	{
		$userIds = array_values(array_unique(array_map('intval', $userIds)));
		if (empty($userIds))
		{
			return ['checkIns' => [], 'stats' => [], 'manualStats' => []];
		}

		$statsByUser = $this->getDailyStatsByUsers($userIds, $timezoneOffset, $timestamp);
		if (empty($statsByUser))
		{
			return ['checkIns' => [], 'stats' => [], 'manualStats' => []];
		}

		$lastCheckInIds = $this->extractLastCheckInIds($statsByUser);
		$checkInsById = $this->resolveLastCheckIns($lastCheckInIds, $userIds, $timezoneOffset, $timestamp);

		$checkIns = [];
		$stats = [];
		foreach ($statsByUser as $statRow)
		{
			$userId = (int)$statRow['USER_ID'];

			$checkIn = $checkInsById[(int)$statRow['LAST_CHECK_IN_ID']] ?? null;
			if ($checkIn === null || !CheckInNormalizer::hasGeo($checkIn))
			{
				continue;
			}

			$stats[$userId] = (int)$statRow['CNT'] + (int)$statRow['HAS_OPEN_ENTER'];
			$checkIns[] = CheckInNormalizer::normalize($checkIn, $timezoneOffset);
		}

		$requestedDate = $this->getRequestedDate($timestamp > 0 ? $timestamp : null, $timezoneOffset);
		$manualStats = $this->getManualStatsByUsers(array_keys($stats), $requestedDate);

		return ['checkIns' => $checkIns, 'stats' => $stats, 'manualStats' => $manualStats];
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, int>
	 */
	private function getManualStatsByUsers(array $userIds, string $requestedDate): array
	{
		if (empty($userIds))
		{
			return [];
		}

		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);
		$rows = $this->repository->getAllUsersManualCheckInByPeriod($userIds, $dateStart, $dateEnd);

		$manualStats = [];
		foreach ($rows as $row)
		{
			if (!$this->isCheckInOnLocalDate($row, $requestedDate))
			{
				continue;
			}

			$userId = (int)$row['USER_ID'];
			$manualStats[$userId] = ($manualStats[$userId] ?? 0) + 1;
		}

		return $manualStats;
	}

	/**
	 * @param array<int, array> $statsByUser
	 * @return int[]
	 */
	private function extractLastCheckInIds(array $statsByUser): array
	{
		$ids = [];
		foreach ($statsByUser as $stats)
		{
			$id = (int)$stats['LAST_CHECK_IN_ID'];
			if ($id > 0)
			{
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @param int[] $lastCheckInIds
	 * @param int[] $userIds
	 * @return array<int, array>
	 */
	private function resolveLastCheckIns(array $lastCheckInIds, array $userIds, int $timezoneOffset, int $timestamp): array
	{
		if (empty($lastCheckInIds))
		{
			return [];
		}

		$checkInRows = $this->repository->getCheckInsByIds($lastCheckInIds);
		$checkInsById = array_column($checkInRows, null, 'ID');

		$unresolvedIds = array_diff($lastCheckInIds, array_map('intval', array_keys($checkInsById)));
		if (empty($unresolvedIds))
		{
			return $checkInsById;
		}

		foreach ($this->findAggregatedCheckInsByIds($unresolvedIds, $userIds, $timezoneOffset, $timestamp) as $id => $point)
		{
			$checkInsById[$id] = $point;
		}

		return $checkInsById;
	}

	/**
	 * @param int[] $unresolvedIds
	 * @param int[] $userIds
	 * @return array<int, array>
	 */
	private function findAggregatedCheckInsByIds(array $unresolvedIds, array $userIds, int $timezoneOffset, int $timestamp): array
	{
		$requestedDate = $this->getRequestedDate($timestamp > 0 ? $timestamp : null, $timezoneOffset);
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);

		$aggregatedPoints = $this->repository->getAllUsersAggregatedPointsByPeriod($userIds, $dateStart, $dateEnd);
		$unresolvedLookup = array_flip($unresolvedIds);

		$found = [];
		foreach ($aggregatedPoints as $point)
		{
			$id = (int)($point['ID'] ?? 0);
			if (isset($unresolvedLookup[$id]))
			{
				$found[$id] = $point;
			}
		}

		return $found;
	}

	/**
	 * @param array $userIds
	 * @param int $timezoneOffset
	 * @param int $timestamp
	 * @return array<int, array> userId => stats row
	 */
	private function getDailyStatsByUsers(array $userIds, int $timezoneOffset, int $timestamp = 0): array
	{
		$requestedDate = $this->getRequestedDate($timestamp > 0 ? $timestamp : null, $timezoneOffset);
		$localDate = new \Bitrix\Main\Type\Date($requestedDate, 'Y-m-d');

		$statsRows = $this->repository->getDailyStatsByUsers($userIds, $localDate);

		$usersWithStats = array_map(static fn($row) => (int)$row['USER_ID'], $statsRows);
		$usersWithoutStats = array_diff($userIds, $usersWithStats);

		if (!empty($usersWithoutStats))
		{
			$this->backFillMissingStats($usersWithoutStats, $requestedDate, $localDate);
			$statsRows = $this->repository->getDailyStatsByUsers($userIds, $localDate);
		}

		$statsByUser = [];
		foreach ($statsRows as $row)
		{
			$statsByUser[(int)$row['USER_ID']] = $row;
		}

		return $statsByUser;
	}

	/**
	 * @param string $requestedDate 'Y-m-d'
	 * @return array{0: DateTime, 1: DateTime}
	 */
	private function getWideDayBoundaries(string $requestedDate): array
	{
		$midnightUtc = strtotime($requestedDate . ' 00:00:00 UTC');
		$endOfDayUtc = $midnightUtc + self::DAY_END_OFFSET;

		return [
			DateTime::createFromTimestamp($midnightUtc - self::MAX_TIMEZONE_OFFSET),
			DateTime::createFromTimestamp($endOfDayUtc + self::MAX_TIMEZONE_OFFSET),
		];
	}

	/**
	 * @param int|null $timestamp
	 * @param int $requesterTimezoneOffset
	 * @return string 'Y-m-d'
	 */
	private function getRequestedDate(?int $timestamp, int $requesterTimezoneOffset): string
	{
		if ($timestamp === null || $timestamp <= 0)
		{
			$timestamp = time();
		}

		return date('Y-m-d', $timestamp + $requesterTimezoneOffset);
	}

	/**
	 * @param array<int, array> $dayRows
	 * @return array<int, array>
	 */
	private function normalizeCheckIns(array $dayRows, int $timezoneOffset, int $currentUserId): array
	{
		$filesMap = (new FileResolver())->resolveMany(
			self::collectCheckInFiles($dayRows),
			$currentUserId,
		);

		$result = [];
		foreach ($dayRows as $item)
		{
			$checkInId = (int)($item['ID'] ?? 0);
			$result[] = CheckInNormalizer::normalize($item, $timezoneOffset, $filesMap[$checkInId] ?? []);
		}

		return $result;
	}

	/**
	 * @return array<int, array>
	 */
	private function collectDayRows(iterable $checkInCollection, array $aggregatedCheckIns, string $requestedDate): array
	{
		$filteredData = [];
		foreach ($checkInCollection as $item)
		{
			$values = is_array($item) ? $item : $item->collectValues();
			if ($this->isCheckInOnLocalDate($values, $requestedDate))
			{
				$filteredData[] = $values;
			}
		}

		foreach ($aggregatedCheckIns as $aggregatedCheckIn)
		{
			if ($this->isCheckInOnLocalDate($aggregatedCheckIn, $requestedDate))
			{
				$filteredData[] = $aggregatedCheckIn;
			}
		}

		usort($filteredData, static function ($a, $b)
		{
			$tsA = $a['DATE_CREATE'] instanceof DateTime ? $a['DATE_CREATE']->getTimestamp() : (int)$a['DATE_CREATE'];
			$tsB = $b['DATE_CREATE'] instanceof DateTime ? $b['DATE_CREATE']->getTimestamp() : (int)$b['DATE_CREATE'];

			return $tsA <=> $tsB ?: ((int)($a['ID'] ?? 0) <=> (int)($b['ID'] ?? 0));
		});

		return $filteredData;
	}

	/**
	 * @return array{track: array<int, array{lat: float, lon: float, time: int, gapBefore: bool}>, markers: array<int, array>}
	 */
	private function buildDayResult(
		array $dayRows,
		int $timezoneOffset,
		int $currentUserId,
		?int $dayCloseTimestamp
	): array
	{
		$rawRowsById = [];
		foreach ($dayRows as $row)
		{
			$rawRowsById[(int)($row['ID'] ?? 0)] = $row;
		}

		$events = self::buildAggregatorEvents($dayRows);
		$markers = (new CheckInMarkerAggregator())->buildMarkers($events, $dayCloseTimestamp);

		$leadingRows = [];
		foreach ($markers as $marker)
		{
			if ($marker['kind'] === CheckInMarkerAggregator::KIND_STAY)
			{
				continue;
			}

			$leadingId = (int)($marker['event']['id'] ?? 0);
			if (isset($rawRowsById[$leadingId]))
			{
				$leadingRows[] = $rawRowsById[$leadingId];
			}
		}

		$filesMap = (new FileResolver())->resolveMany(
			self::collectCheckInFiles($leadingRows),
			$currentUserId,
		);

		$normalizedMarkers = [];
		foreach ($markers as $marker)
		{
			$leadingId = (int)($marker['event']['id'] ?? 0);
			$normalizedMarkers[] = CheckInMarkerNormalizer::normalize(
				$marker,
				$rawRowsById,
				$filesMap[$leadingId] ?? [],
				$timezoneOffset,
			);
		}

		return [
			'track' => self::buildTrack($dayRows),
			'markers' => $normalizedMarkers,
		];
	}

	/**
	 * Every point carries the id and the address of the check-in it stands on: the ends of the route are
	 * technical moving points absent from `markers`, so this is the only path by which the address queue
	 * reaches them and by which the resolved address (pull patch, keyed by check-in id) gets back.
	 *
	 * @param array<int, array> $rows
	 * @return array<int, array{id: int, lat: float, lon: float, time: int, gapBefore: bool, address: string}>
	 */
	private static function buildTrack(array $rows): array
	{
		$track = [];
		$prev = null;
		foreach ($rows as $row)
		{
			$latitude = $row['LATITUDE'] ?? null;
			$longitude = $row['LONGITUDE'] ?? null;
			if ($latitude === null || $longitude === null)
			{
				continue;
			}

			$dateCreate = $row['DATE_CREATE'] ?? null;
			$timestamp = $dateCreate instanceof DateTime ? $dateCreate->getTimestamp() : (int)$dateCreate;
			$lat = (float)$latitude;
			$lon = (float)$longitude;

			$gapBefore = false;
			if ($prev !== null)
			{
				$deltaSec = $timestamp - $prev['time'];
				$movedMeters = self::haversineMeters($prev['lat'], $prev['lon'], $lat, $lon);
				$gapBefore = $deltaSec > self::GAP_THRESHOLD_SEC && $movedMeters > self::GAP_DISTANCE_METERS;
			}

			$point = [
				'id' => (int)($row['ID'] ?? 0),
				'lat' => $lat,
				'lon' => $lon,
				'time' => $timestamp,
				'gapBefore' => $gapBefore,
				'address' => (string)($row['ADDRESS'] ?? ''),
			];
			$track[] = $point;
			$prev = $point;
		}

		return $track;
	}

	private static function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
	{
		$dLat = deg2rad($lat2 - $lat1);
		$dLon = deg2rad($lon2 - $lon1);
		$a = (sin($dLat / 2) ** 2)
			+ (cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLon / 2) ** 2));

		return 2 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($a)));
	}

	/**
	 * @param array<int, array> $rows
	 * @return array<int, array{id: int, entityType: int, description: string, latitude: ?float, longitude: ?float, address: string, timestamp: int, userTimezone: int}>
	 */
	private static function buildAggregatorEvents(array $rows): array
	{
		$events = [];
		foreach ($rows as $row)
		{
			$dateCreate = $row['DATE_CREATE'] ?? null;
			$events[] = [
				'id' => (int)($row['ID'] ?? 0),
				'entityType' => (int)($row['ENTITY_TYPE'] ?? 0),
				'description' => (string)($row['DESCRIPTION'] ?? ''),
				'latitude' => ($row['LATITUDE'] ?? null) !== null ? (float)$row['LATITUDE'] : null,
				'longitude' => ($row['LONGITUDE'] ?? null) !== null ? (float)$row['LONGITUDE'] : null,
				'address' => (string)($row['ADDRESS'] ?? ''),
				'timestamp' => $dateCreate instanceof DateTime ? $dateCreate->getTimestamp() : (int)$dateCreate,
				'userTimezone' => (int)($row['USER_TIMEZONE'] ?? 0),
			];
		}

		return $events;
	}

	/**
	 * Excludes check-ins without geodata (coordinates reset by the cleanup agent) from the map output.
	 * So that a cleaned check-in is not displayed as a point (0, 0).
	 *
	 * @param array<int, array> $checkIns
	 * @return array<int, array>
	 */
	private static function dropCheckInsWithoutGeo(array $checkIns): array
	{
		return array_values(array_filter(
			$checkIns,
			static fn(array $checkIn): bool => ($checkIn['latitude'] ?? null) !== null && ($checkIn['longitude'] ?? null) !== null,
		));
	}

	/**
	 * @return array<int, array{id: int, fileIds: array}>
	 */
	private static function collectCheckInFiles(array $checkIns): array
	{
		$result = [];
		foreach ($checkIns as $checkIn)
		{
			$fileIds = is_array($checkIn['FILE_IDS'] ?? null) ? $checkIn['FILE_IDS'] : [];
			if (!empty($fileIds))
			{
				$result[] = [
					'id' => (int)($checkIn['ID'] ?? 0),
					'fileIds' => $fileIds,
				];
			}
		}

		return $result;
	}

	private function backFillMissingStats(array $userIds, string $requestedDate, Main\Type\Date $localDate): void
	{
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);

		$allCheckInRows = $this->repository->getAllUsersCheckInByPeriod($userIds, $dateStart, $dateEnd);
		$allAggregatedPoints = $this->repository->getAllUsersAggregatedPointsByPeriod($userIds, $dateStart, $dateEnd);

		$checkInsByUser = [];
		foreach (array_merge($allCheckInRows, $allAggregatedPoints) as $row)
		{
			if ($this->isCheckInOnLocalDate($row, $requestedDate))
			{
				$checkInsByUser[(int)$row['USER_ID']][] = $row;
			}
		}

		$statsUpdater = new DailyStatsUpdater();

		foreach ($userIds as $userId)
		{
			$allCheckIns = $checkInsByUser[$userId] ?? [];
			if (empty($allCheckIns))
			{
				continue;
			}

			usort($allCheckIns, static function ($a, $b)
			{
				$tsA = ($a['DATE_CREATE'] instanceof DateTime ? $a['DATE_CREATE']->getTimestamp() : (int)$a['DATE_CREATE']) + (int)($a['USER_TIMEZONE'] ?? 0);
				$tsB = ($b['DATE_CREATE'] instanceof DateTime ? $b['DATE_CREATE']->getTimestamp() : (int)$b['DATE_CREATE']) + (int)($b['USER_TIMEZONE'] ?? 0);

				return $tsA <=> $tsB ?: ((int)($a['ID'] ?? 0) <=> (int)($b['ID'] ?? 0));
			});

			$statsUpdater->backFillForUser($userId, $allCheckIns, $localDate);
		}
	}

	private function isCheckInOnLocalDate(array $checkIn, string $requestedDate): bool
	{
		$dateCreate = $checkIn['DATE_CREATE'] ?? null;

		if ($dateCreate instanceof DateTime)
		{
			$timestamp = $dateCreate->getTimestamp();
		}
		elseif (is_int($dateCreate))
		{
			$timestamp = $dateCreate;
		}
		else
		{
			return false;
		}

		$userTimezone = (int)($checkIn['USER_TIMEZONE'] ?? 0);
		$localDate = date('Y-m-d', $timestamp + $userTimezone);

		return $localDate === $requestedDate;
	}
}

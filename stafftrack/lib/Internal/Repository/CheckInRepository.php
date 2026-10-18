<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internal\Model\CheckInTable;
use Bitrix\StaffTrack\Internal\Model\CheckInByDayTable;
use Bitrix\StaffTrack\Internal\Model\CheckInCollection;
use Bitrix\StaffTrack\Internal\Model\CheckInDailyStatsTable;

class CheckInRepository
{
	private const ALL_FIELDS = [
		'ID',
		'USER_ID',
		'DATE_CREATE',
		'ENTITY_TYPE',
		'LATITUDE',
		'LONGITUDE',
		'DESCRIPTION',
		'ADDRESS',
		'MESSAGE_IDS',
		'USER_TIMEZONE',
		'FILE_IDS',
	];

	private const ENTITY_TYPE = [
		'MANUAL' => 1,
		'AUTOMATIC' => 2,
	];

	private const ROUTE_FIELDS = [
		'ID',
		'USER_ID',
		'DATE_CREATE',
		'ENTITY_TYPE',
		'LATITUDE',
		'LONGITUDE',
		'DESCRIPTION',
		'ADDRESS',
		'USER_TIMEZONE',
	];

	/**
	 * @param int $userId
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return CheckInCollection
	 */
	public function getUserCheckInByPeriod(int $userId, DateTime $dateStart, DateTime $dateEnd): CheckInCollection
	{
		return CheckInTable::query()
			->setSelect(self::ALL_FIELDS)
			->where('USER_ID', $userId)
			->whereBetween('DATE_CREATE', $dateStart, $dateEnd)
			->setOrder(['DATE_CREATE' => 'ASC'])
			->fetchCollection()
		;
	}

	public function getUserManualCheckInByPeriod(int $userId, DateTime $dateStart, DateTime $dateEnd): CheckInCollection
	{
		return CheckInTable::query()
			->setSelect(self::ALL_FIELDS)
			->where('USER_ID', $userId)
			->where('ENTITY_TYPE', self::ENTITY_TYPE['MANUAL'])
			->whereBetween('DATE_CREATE', $dateStart, $dateEnd)
			->setOrder(['DATE_CREATE' => 'ASC'])
			->fetchCollection()
		;
	}

	/**
	 * @param int $userId
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return CheckInCollection
	 */
	public function getUserRouteByPeriod(int $userId, DateTime $dateStart, DateTime $dateEnd): CheckInCollection
	{
		return CheckInTable::query()
			->setSelect(self::ROUTE_FIELDS)
			->where('USER_ID', $userId)
			->whereBetween('DATE_CREATE', $dateStart, $dateEnd)
			->setOrder(['DATE_CREATE' => 'ASC'])
			->fetchCollection()
			;
	}

	/**
	 * @param array $userIds
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return array
	 */
	public function getAllUsersCheckInByPeriod(array $userIds, DateTime $dateStart, DateTime $dateEnd): array
	{
		$userIds = array_map('intval', $userIds);
		if (empty($userIds))
		{
			return [];
		}

		return CheckInTable::query()
			->setSelect(self::ALL_FIELDS)
			->whereIn('USER_ID', $userIds)
			->whereBetween('DATE_CREATE', $dateStart, $dateEnd)
			->setOrder(['DATE_CREATE' => 'ASC'])
			->exec()
			->fetchAll()
		;
	}

	/**
	 * @param array $userIds
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return array
	 */
	public function getAllUsersManualCheckInByPeriod(array $userIds, DateTime $dateStart, DateTime $dateEnd): array
	{
		$userIds = array_map('intval', $userIds);
		if (empty($userIds))
		{
			return [];
		}

		return CheckInTable::query()
			->setSelect(['USER_ID', 'DATE_CREATE', 'USER_TIMEZONE'])
			->whereIn('USER_ID', $userIds)
			->where('ENTITY_TYPE', self::ENTITY_TYPE['MANUAL'])
			->whereBetween('DATE_CREATE', $dateStart, $dateEnd)
			->exec()
			->fetchAll()
		;
	}

	/**
	 * Only the columns needed to resolve the local date of a manual check-in.
	 *
	 * @param int $userId
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return array
	 */
	public function getUserManualCheckInDatesByPeriod(int $userId, DateTime $dateStart, DateTime $dateEnd): array
	{
		return CheckInTable::query()
			->setSelect(['DATE_CREATE', 'USER_TIMEZONE'])
			->where('USER_ID', $userId)
			->where('ENTITY_TYPE', self::ENTITY_TYPE['MANUAL'])
			->whereBetween('DATE_CREATE', $dateStart, $dateEnd)
			->exec()
			->fetchAll()
		;
	}

	/**
	 * @param int $userId
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return array
	 */
	public function getAggregatedPointsByPeriod(int $userId, DateTime $dateStart, DateTime $dateEnd): array
	{
		return $this->fetchAggregatedPoints([$userId], $dateStart, $dateEnd);
	}

	public function getUserAggregatedManualPointsByPeriod(int $userId, DateTime $dateStart, DateTime $dateEnd): array
	{
		return $this->fetchAggregatedPoints([$userId], $dateStart, $dateEnd, self::ENTITY_TYPE['MANUAL']);
	}

	/**
	 * @param array $userIds
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return array
	 */
	public function getAllUsersAggregatedPointsByPeriod(array $userIds, DateTime $dateStart, DateTime $dateEnd): array
	{
		$userIds = array_map('intval', $userIds);
		if (empty($userIds))
		{
			return [];
		}

		return $this->fetchAggregatedPoints($userIds, $dateStart, $dateEnd);
	}

	private function fetchAggregatedPoints(array $userIds, DateTime $dateStart, DateTime $dateEnd, ?int $entityType = null): array
	{
		$routes = CheckInByDayTable::query()
			->setSelect([
				'ID',
				'USER_ID',
				'DATE_CREATE',
				'DATE_END',
				'GEO_DATA',
			])
			->whereIn('USER_ID', $userIds)
			->where('DATE_CREATE', '<=', $dateEnd)
			->where('DATE_END', '>=', $dateStart)
			->setOrder(['DATE_CREATE' => 'ASC'])
			->exec();

		$points = [];
		$startTs = $dateStart->getTimestamp();
		$endTs = $dateEnd->getTimestamp();

		while ($route = $routes->fetch())
		{
			$geoJson = (string)($route['GEO_DATA'] ?? '');
			if ($geoJson === '')
			{
				continue;
			}

			$geoData = json_decode($geoJson, true);
			if (!is_array($geoData))
			{
				continue;
			}

			foreach ($geoData as $point)
			{
				$ts = (int)($point['DATE_CREATE'] ?? 0);
				if ($ts < $startTs || $ts > $endTs)
				{
					continue;
				}

				if ($entityType !== null && (int)($point['ENTITY_TYPE'] ?? 0) !== $entityType)
				{
					continue;
				}

				$points[] = $point;
			}
		}

		return $points;
	}

	/**
	 * @param int $userId
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return array
	 */
	public function getCheckInRowsForAggregation(int $userId, DateTime $dateStart, DateTime $dateEnd): array
	{
		return CheckInTable::query()
			->setSelect(self::ALL_FIELDS)
			->where('USER_ID', $userId)
			->whereBetween('DATE_CREATE', $dateStart, $dateEnd)
			->setOrder(['DATE_CREATE' => 'ASC'])
			->exec()
			->fetchAll()
		;
	}

	/**
	 * @param int $userId
	 * @param DateTime $threshold
	 * @return array
	 */
	public function getStaleCheckInRows(int $userId, DateTime $threshold, int $limit = 500): array
	{
		return CheckInTable::query()
			->setSelect(self::ALL_FIELDS)
			->where('USER_ID', $userId)
			->where('DATE_CREATE', '<=', $threshold)
			->setOrder(['DATE_CREATE' => 'ASC'])
			->setLimit($limit)
			->exec()
			->fetchAll()
		;
	}

	/**
	 * @param array $userIds
	 * @param Date $localDate
	 * @return array
	 */
	public function getDailyStatsByUsers(array $userIds, Date $localDate): array
	{
		$userIds = array_map('intval', $userIds);
		if (empty($userIds))
		{
			return [];
		}

		return CheckInDailyStatsTable::query()
			->setSelect(['USER_ID', 'CNT', 'HAS_OPEN_ENTER', 'LAST_CHECK_IN_ID'])
			->whereIn('USER_ID', $userIds)
			->where('LOCAL_DATE', $localDate)
			->exec()
			->fetchAll()
		;
	}

	/**
	 * @param array $checkInIds
	 * @return array
	 */
	public function getCheckInsByIds(array $checkInIds): array
	{
		$checkInIds = array_map('intval', array_filter($checkInIds));
		if (empty($checkInIds))
		{
			return [];
		}

		return CheckInTable::query()
			->setSelect(self::ALL_FIELDS)
			->whereIn('ID', $checkInIds)
			->exec()
			->fetchAll()
		;
	}

	public function updateAddressById(int $checkInId, string $address): void
	{
		CheckInTable::update($checkInId, ['ADDRESS' => $address]);
	}

	public function updateAddressByIds(array $checkInIds, string $address): void
	{
		if (empty($checkInIds))
		{
			return;
		}

		CheckInTable::updateMulti($checkInIds, ['ADDRESS' => $address], true);
	}

	/**
	 * @param DateTime $windowStart
	 * @param DateTime $threshold
	 * @param int $lastId
	 * @param int $limit
	 * @return array
	 */
	public function getStaleRawCheckIns(DateTime $windowStart, DateTime $threshold, int $lastId = 0, int $limit = 500): array
	{
		return CheckInTable::query()
			->setSelect(['ID', 'LATITUDE', 'LONGITUDE', 'ADDRESS', 'MESSAGE_IDS'])
			->where('DATE_CREATE', '>', $windowStart)
			->where('DATE_CREATE', '<=', $threshold)
			->where('ID', '>', $lastId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->exec()
			->fetchAll()
		;
	}

	/**
	 * @param int[] $ids
	 */
	public function clearGeoFieldsByIds(array $ids): void
	{
		$ids = array_values(array_filter(array_map('intval', $ids)));
		if (empty($ids))
		{
			return;
		}

		CheckInTable::updateMulti($ids, [
			'LATITUDE' => null,
			'LONGITUDE' => null,
			'ADDRESS' => null,
			'MESSAGE_IDS' => null,
		], true);
	}
}

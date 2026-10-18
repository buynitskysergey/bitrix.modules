<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internal\Model\CheckInByDayTable;

class CheckInByDayRepository
{
	/**
	 * @param DateTime $windowStart
	 * @param DateTime $threshold
	 * @param int $lastId
	 * @param int $limit
	 * @return array
	 */
	public function getStaleByDayRows(DateTime $windowStart, DateTime $threshold, int $lastId = 0, int $limit = 500): array
	{
		return CheckInByDayTable::query()
			->setSelect(['ID', 'GEO_DATA'])
			->where('DATE_END', '>', $windowStart)
			->where('DATE_END', '<=', $threshold)
			->where('ID', '>', $lastId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->exec()
			->fetchAll()
		;
	}

	/**
	 * @param int $id
	 * @param string $geoDataJson
	 */
	public function updateGeoData(int $id, string $geoDataJson): void
	{
		CheckInByDayTable::update($id, ['GEO_DATA' => $geoDataJson]);
	}
}
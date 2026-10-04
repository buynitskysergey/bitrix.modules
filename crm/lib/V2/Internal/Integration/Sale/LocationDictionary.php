<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Sale;

use Bitrix\Main\Loader;
use Bitrix\Sale\Location\LocationTable;

/**
 * @internal
 */
class LocationDictionary
{
	/**
	 * @return int[]
	 */
	public function getAllId(array $filterLocationIds): array
	{
		if (!$this->isLocationSearchAvailable() || empty($filterLocationIds))
		{
			return [];
		}

		$result = [];
		$iterator = LocationTable::getList([
			'select' => [
				'ID',
			],
			'filter' => [
				'@ID' => array_values(array_unique($filterLocationIds)),
			],
		]);
		while ($row = $iterator->fetch())
		{
			$result[] = (int)$row['ID'];
		}

		return $result;
	}

	protected function isLocationSearchAvailable(): bool
	{
		return
			Loader::includeModule('sale')
			&& class_exists('\\Bitrix\\Sale\\Location\\LocationTable')
		;
	}
}

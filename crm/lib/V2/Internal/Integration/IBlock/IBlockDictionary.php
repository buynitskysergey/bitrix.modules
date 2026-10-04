<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\IBlock;

use Bitrix\Iblock;
use Bitrix\Main\Loader;

/**
 * @internal
 */
class IBlockDictionary
{
	/**
	 * @param int[] $filterElementIds
	 * @return int[]
	 */
	public function getElementsId(int $iBlockId, array $filterElementIds): array
	{
		if ($iBlockId <= 0 || !$this->isIBlockElementAvailable() || empty($filterElementIds))
		{
			return [];
		}

		$result = [];
		$iterator = Iblock\ElementTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=ACTIVE' => 'Y',
				'=IBLOCK_ID' => $iBlockId,
				'@ID' => array_values(array_unique($filterElementIds)),
			],
		]);
		while ($row = $iterator->fetch())
		{
			$result[] = (int)$row['ID'];
		}

		return $result;
	}

	/**
	 * @param int[] $filterSectionIds
	 * @return int[]
	 */
	public function getSectionsId(int $iBlockId, array $filterSectionIds): array
	{
		if ($iBlockId <= 0 || !$this->isIBlockSectionAvailable() || empty($filterSectionIds))
		{
			return [];
		}

		$result = [];
		$iterator = Iblock\SectionTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=ACTIVE' => 'Y',
				'=IBLOCK_ID' => $iBlockId,
				'@ID' => array_values($filterSectionIds),
			],
		]);
		while ($row = $iterator->fetch())
		{
			$result[] = (int)$row['ID'];
		}

		return $result;
	}

	protected function isIBlockElementAvailable(): bool
	{
		return Loader::includeModule('iblock') && class_exists('\\Bitrix\\Iblock\\ElementTable');
	}

	protected function isIBlockSectionAvailable(): bool
	{
		return Loader::includeModule('iblock') && class_exists('\\Bitrix\\Iblock\\SectionTable');
	}
}

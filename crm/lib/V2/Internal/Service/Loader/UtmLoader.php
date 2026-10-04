<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Loader;

use Bitrix\Crm\UtmTable;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\Entity\Item\Utm;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;

class UtmLoader
{
	private const BATCH_SIZE = 500;

	/**
	 * @param Item[] $items
	 */
	public function load(array $items, ItemSelect $select): void
	{
		if (!$select->shouldLoadUtm())
		{
			return;
		}

		$persisted = array_values(array_filter($items, static fn(Item $item) => $item->getId() !== null));
		if (empty($persisted))
		{
			return;
		}

		$entityTypeId = $persisted[0]->getEntityType()->getId();
		$ownerIds = array_values(array_unique(array_map(static fn(Item $item) => (int)$item->getId(), $persisted)));
		$utmByOwner = $this->fetchBulk($entityTypeId, $ownerIds);

		foreach ($persisted as $item)
		{
			$item->internalSet(Item::utm, $utmByOwner[(int)$item->getId()] ?? null);
		}
	}

	/**
	 * @param int[] $ownerIds
	 * @return array<int, Utm>
	 */
	private function fetchBulk(int $entityTypeId, array $ownerIds): array
	{
		$utmByOwner = [];
		foreach (array_chunk($ownerIds, self::BATCH_SIZE) as $ownerIdsChunk)
		{
			foreach ($this->fetchRowsChunk($entityTypeId, $ownerIdsChunk) as $row)
			{
				$ownerId = (int)$row['ENTITY_ID'];
				$utmByOwner[$ownerId] ??= new Utm();

				match ($row['CODE'])
				{
					UtmTable::ENUM_CODE_UTM_SOURCE => $utmByOwner[$ownerId]->setSource($row['VALUE']),
					UtmTable::ENUM_CODE_UTM_MEDIUM => $utmByOwner[$ownerId]->setMedium($row['VALUE']),
					UtmTable::ENUM_CODE_UTM_CAMPAIGN => $utmByOwner[$ownerId]->setCampaign($row['VALUE']),
					UtmTable::ENUM_CODE_UTM_CONTENT => $utmByOwner[$ownerId]->setContent($row['VALUE']),
					UtmTable::ENUM_CODE_UTM_TERM => $utmByOwner[$ownerId]->setTerm($row['VALUE']),
					default => null,
				};
			}
		}

		return $utmByOwner;
	}

	/** @param int[] $ownerIds */
	protected function fetchRowsChunk(int $entityTypeId, array $ownerIds): iterable
	{
		$iterator = UtmTable::query()
			->setSelect(['ENTITY_ID', 'CODE', 'VALUE'])
			->where('ENTITY_TYPE_ID', $entityTypeId)
			->whereIn('ENTITY_ID', $ownerIds)
			->exec()
		;
		while ($row = $iterator->fetch())
		{
			yield $row;
		}
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Loader;

use Bitrix\Crm\Observer\Entity\ObserverTable;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;

/**
 * Loads the list of observer user IDs onto V2 Items.
 *
 * Data lives in `b_crm_observer` keyed by `(ENTITY_TYPE_ID, ENTITY_ID, USER_ID)` — a single
 * shared table for every entity type. Items get a non-null `int[]` even when the owner has no
 * observers, so callers can use null vs. empty as the "loaded?" signal.
 *
 * @internal
 */
final class ObserverLoader
{
	/**
	 * @param Item[] $items
	 */
	public function load(array $items, ItemSelect $select): void
	{
		if (!$select->shouldLoadObservers())
		{
			return;
		}

		$persisted = array_values(array_filter($items, static fn(Item $i) => $i->getId() !== null));
		if (empty($persisted))
		{
			return;
		}

		$entityTypeId = $persisted[0]->getEntityType()->getId();
		$ownerIds = array_map(static fn(Item $i) => (int)$i->getId(), $persisted);

		$observersByOwner = $this->fetchBulk($entityTypeId, $ownerIds);

		foreach ($persisted as $item)
		{
			$item->internalSet(Item::observers, $observersByOwner[(int)$item->getId()] ?? []);
		}
	}

	/**
	 * @param int[] $ownerIds
	 * @return array<int, int[]> ownerId => userIds
	 */
	private function fetchBulk(int $entityTypeId, array $ownerIds): array
	{
		$rows = ObserverTable::query()
			->setSelect(['ENTITY_ID', 'USER_ID'])
			->where('ENTITY_TYPE_ID', $entityTypeId)
			->whereIn('ENTITY_ID', $ownerIds)
			->setOrder(['ENTITY_ID' => 'ASC', 'SORT' => 'ASC'])
			->fetchAll()
		;

		$bucket = [];
		foreach ($rows as $row)
		{
			$bucket[(int)$row['ENTITY_ID']][] = (int)$row['USER_ID'];
		}

		return $bucket;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Loader;

use Bitrix\Crm\Relation\EntityRelationTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;

/**
 * Loads the parent bindings (one per parent entity type) onto V2 Items.
 *
 * Data lives in `b_crm_entity_relation`, keyed by `(SRC_ENTITY_TYPE_ID, SRC_ENTITY_ID,
 * DST_ENTITY_TYPE_ID, DST_ENTITY_ID)` - `DST` is the child, `SRC` is the parent. A single batch
 * query covers the whole item set (never N+1). Only the parent types the entity currently declares
 * are queried; entity types with no parent relations are skipped.
 *
 * Each child keeps at most one parent per type (ADR invariant). The storage has no surrogate id,
 * so on the (abnormal) duplicate the read deterministically keeps the parent with the smallest id.
 * Values are written through {@see Item::internalSet()} so hydration leaves change-tracking clean.
 *
 * @internal
 */
final class ParentLoader
{
	/**
	 * @param Item[] $items
	 */
	public function load(array $items, ItemSelect $select): void
	{
		if (!$select->shouldLoadParents())
		{
			return;
		}

		$persisted = array_values(array_filter($items, static fn(Item $i) => $i->getId() !== null));
		if (empty($persisted))
		{
			return;
		}

		$entityType = $persisted[0]->getEntityType();
		$entityTypeId = $entityType->getId();
		$parentTypeIds = [];
		foreach (Container::getInstance()->getRelationManager()->getRelations($entityTypeId) as $relation)
		{
			if ($relation->isPredefined() || $relation->getChildEntityTypeId() !== $entityTypeId)
			{
				continue;
			}

			$parentTypeIds[] = $relation->getParentEntityTypeId();
		}
		$parentTypeIds = array_values(array_unique($parentTypeIds));
		if (empty($parentTypeIds))
		{
			return;
		}

		$childIds = array_map(static fn(Item $i) => (int)$i->getId(), $persisted);

		// (childId, parentTypeId) => min parent id - dedup with deterministic min() choice.
		$minParentByChildAndType = $this->fetchMinParents($entityTypeId, $childIds, $parentTypeIds);

		foreach ($persisted as $item)
		{
			foreach ($parentTypeIds as $parentTypeId)
			{
				$parentId = $minParentByChildAndType[(int)$item->getId()][$parentTypeId] ?? null;
				if ($parentId !== null)
				{
					// V2 hydration key (mirrors Item::setParentId's change-tracking key), not the
					// legacy 'PARENT_ID_<typeId>' ORM column.
					$item->internalSet('parentId_' . $parentTypeId, $parentId);
				}
			}
		}
	}

	/**
	 * @param int[] $childIds
	 * @param int[] $parentTypeIds
	 * @return array<int, array<int, int>> childId => parentTypeId => min parent id
	 */
	private function fetchMinParents(int $childEntityTypeId, array $childIds, array $parentTypeIds): array
	{
		$rows = EntityRelationTable::query()
			->setSelect(['SRC_ENTITY_TYPE_ID', 'SRC_ENTITY_ID', 'DST_ENTITY_ID'])
			->where('DST_ENTITY_TYPE_ID', $childEntityTypeId)
			->whereIn('DST_ENTITY_ID', $childIds)
			->whereIn('SRC_ENTITY_TYPE_ID', $parentTypeIds)
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$childId = (int)$row['DST_ENTITY_ID'];
			$parentTypeId = (int)$row['SRC_ENTITY_TYPE_ID'];
			$parentId = (int)$row['SRC_ENTITY_ID'];

			$current = $result[$childId][$parentTypeId] ?? null;
			if ($current === null || $parentId < $current)
			{
				$result[$childId][$parentTypeId] = $parentId;
			}
		}

		return $result;
	}
}

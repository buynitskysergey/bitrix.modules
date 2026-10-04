<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item;

use Bitrix\Crm\V2\Internal\Repository\Item\DealRepository;
use Bitrix\Crm\V2\Internal\Repository\Item\ItemRepositoryInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Deal;
use Bitrix\Crm\V2\Public\Entity\Item\ItemCollection;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;

/**
 * @method ?Deal getById(int $id, ItemSelect|string[] $select)
 * @method ItemCollection<Deal> getByIds(int[] $ids, ItemSelect|string[] $select)
 * @method ItemCollection<Deal> getList(\Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect|string[] $select, ?\Bitrix\Crm\V2\Public\Provider\Item\Param\ItemFilter $filter = null, ?\Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSort $sort = null, ?\Bitrix\Main\Provider\Params\PagerInterface $pager = null)
 */
final class DealProvider extends AbstractItemProvider
{
	protected function getEntityType(): EntityType
	{
		return EntityType::deal();
	}

	protected function createRepository(): ItemRepositoryInterface
	{
		return new DealRepository();
	}
}

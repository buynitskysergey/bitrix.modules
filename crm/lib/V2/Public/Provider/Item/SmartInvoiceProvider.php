<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item;

use Bitrix\Crm\V2\Internal\Repository\Item\ItemRepositoryInterface;
use Bitrix\Crm\V2\Internal\Repository\Item\SmartItemRepository;
use Bitrix\Crm\V2\Public\Entity\Item\ItemCollection;
use Bitrix\Crm\V2\Public\Entity\Item\SmartInvoice;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;

/**
 * @method ?SmartInvoice getById(int $id, ItemSelect|string[] $select)
 * @method ItemCollection<SmartInvoice> getByIds(int[] $ids, ItemSelect|string[] $select)
 * @method ItemCollection<SmartInvoice> getList(\Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect|string[] $select, ?\Bitrix\Crm\V2\Public\Provider\Item\Param\ItemFilter $filter = null, ?\Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSort $sort = null, ?\Bitrix\Main\Provider\Params\PagerInterface $pager = null)
 */
final class SmartInvoiceProvider extends AbstractItemProvider
{
	protected function getEntityType(): EntityType
	{
		return EntityType::smartInvoice();
	}

	protected function createRepository(): ItemRepositoryInterface
	{
		return new SmartItemRepository($this->getEntityType());
	}
}

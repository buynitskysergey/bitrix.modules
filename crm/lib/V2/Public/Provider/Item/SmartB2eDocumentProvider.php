<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item;

use Bitrix\Crm\V2\Internal\Repository\Item\ItemRepositoryInterface;
use Bitrix\Crm\V2\Internal\Repository\Item\SmartItemRepository;
use Bitrix\Crm\V2\Public\Entity\Item\ItemCollection;
use Bitrix\Crm\V2\Public\Entity\Item\SmartB2eDocument;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;

/**
 * @method ?SmartB2eDocument getById(int $id, ItemSelect|string[] $select)
 * @method ItemCollection<SmartB2eDocument> getByIds(int[] $ids, ItemSelect|string[] $select)
 * @method ItemCollection<SmartB2eDocument> getList(\Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect|string[] $select, ?\Bitrix\Crm\V2\Public\Provider\Item\Param\ItemFilter $filter = null, ?\Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSort $sort = null, ?\Bitrix\Main\Provider\Params\PagerInterface $pager = null)
 */
final class SmartB2eDocumentProvider extends AbstractItemProvider
{
	protected function getEntityType(): EntityType
	{
		return EntityType::smartB2eDocument();
	}

	protected function createRepository(): ItemRepositoryInterface
	{
		return new SmartItemRepository($this->getEntityType());
	}
}

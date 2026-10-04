<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item;

use Bitrix\Crm\V2\Internal\Repository\Item\CompanyRepository;
use Bitrix\Crm\V2\Internal\Repository\Item\ItemRepositoryInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Company;
use Bitrix\Crm\V2\Public\Entity\Item\ItemCollection;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;

/**
 * @method ?Company getById(int $id, ItemSelect|string[] $select)
 * @method ItemCollection<Company> getByIds(int[] $ids, ItemSelect|string[] $select)
 * @method ItemCollection<Company> getList(\Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect|string[] $select, ?\Bitrix\Crm\V2\Public\Provider\Item\Param\ItemFilter $filter = null, ?\Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSort $sort = null, ?\Bitrix\Main\Provider\Params\PagerInterface $pager = null)
 */
final class CompanyProvider extends AbstractItemProvider
{
	protected function getEntityType(): EntityType
	{
		return EntityType::company();
	}

	protected function createRepository(): ItemRepositoryInterface
	{
		return new CompanyRepository();
	}
}

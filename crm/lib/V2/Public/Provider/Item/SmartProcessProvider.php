<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item;

use Bitrix\Crm\V2\Internal\Repository\Item\ItemRepositoryInterface;
use Bitrix\Crm\V2\Internal\Repository\Item\SmartItemRepository;
use Bitrix\Crm\V2\Public\Entity\Item\ItemCollection;
use Bitrix\Crm\V2\Public\Entity\Item\SmartProcess;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;
use Bitrix\Main\ArgumentException;

/**
 * Read-side provider for custom smart-process (SPA) entity types.
 * Static smart entities (SmartInvoice / SmartDocument / SmartB2eDocument) have their own
 * typed providers.
 *
 * @method ?SmartProcess getById(int $id, ItemSelect|string[] $select)
 * @method ItemCollection<SmartProcess> getByIds(int[] $ids, ItemSelect|string[] $select)
 * @method ItemCollection<SmartProcess> getList(\Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect|string[] $select, ?\Bitrix\Crm\V2\Public\Provider\Item\Param\ItemFilter $filter = null, ?\Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSort $sort = null, ?\Bitrix\Main\Provider\Params\PagerInterface $pager = null)
 */
final class SmartProcessProvider extends AbstractItemProvider
{
	public function __construct(private readonly EntityType $entityType)
	{
		if (!$entityType->isSmartProcess())
		{
			throw new ArgumentException("Not a smart-process entity type: {$entityType->getId()}");
		}
	}

	protected function getEntityType(): EntityType
	{
		return $this->entityType;
	}

	protected function createRepository(): ItemRepositoryInterface
	{
		return new SmartItemRepository($this->entityType);
	}
}

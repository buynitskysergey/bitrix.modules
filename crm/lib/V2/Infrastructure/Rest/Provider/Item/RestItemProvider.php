<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Provider\Item;

use Bitrix\Crm\V2\Public\Entity\Item\Company;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\Entity\Item\ItemCollection;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Crm\V2\Public\Provider\Item\AbstractItemProvider;
use Bitrix\Crm\V2\Public\Provider\Item\AccessMode;
use Bitrix\Crm\V2\Public\Provider\Item\ItemProvider;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemFilter;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSort;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;

final class RestItemProvider
{
	public function __construct(
		private readonly EntityType $entityType,
		private readonly int $userId,
		private readonly ?AbstractItemProvider $provider = null,
	)
{
}

	/**
	 * @param ItemSelect|string[] $select
	 */
	public function getById(int $itemId, ItemSelect|array $select): ?Item
	{
		$provider = $this->getProvider();
		$select = ItemSelect::from($select)->withFields(...$this->getVisibilityFields());
		$item = $provider->getById($itemId, $select);
		if ($item === null || !$this->isVisible($item))
		{
			return null;
		}

		return $item;
	}

	/**
	 * @param ItemSelect|string[] $select
	 */
	public function getList(
		ItemSelect|array $select,
		?ItemFilter $filter = null,
		?ItemSort $sort = null,
		?PagerInterface $pager = null,
	): ItemCollection
	{
		$filter = $this->applyListFilter($filter);

		return $this->getProvider()->getList($select, $filter, $sort, $pager);
	}

	private function applyListFilter(?ItemFilter $filter): ?ItemFilter
	{
		$restrictions = $this->getListRestrictions();
		if ($restrictions === [])
		{
			return $filter;
		}

		if ($filter === null)
		{
			return new ItemFilter($restrictions);
		}

		$rawFilter = $filter->getRaw();
		if (is_array($rawFilter))
		{
			return new ItemFilter(array_merge($rawFilter, $restrictions));
		}

		$combinedFilter = new ConditionTree();
		$combinedFilter->where($rawFilter);
		foreach ($restrictions as $field => $value)
		{
			$combinedFilter->where($this->getFieldNameWithoutOperator($field), '=', $value);
		}

		return new ItemFilter($combinedFilter);
	}

	private function isVisible(Item $item): bool
	{
		if (method_exists($item, 'getIsRecurring') && $item->getIsRecurring() === true)
		{
			return false;
		}

		return !($item instanceof Company && $item->getIsMyCompany() === true);
	}

	/**
	 * @return array<string, string>
	 */
	private function getListRestrictions(): array
	{
		$restrictions = [];
		if (EntityTypeSettings::of($this->entityType)->isRecurringSupported())
		{
			$restrictions['=isRecurring'] = 'N';
		}

		if ($this->entityType->getId() === OwnerType::COMPANY)
		{
			$restrictions['=isMyCompany'] = 'N';
		}

		return $restrictions;
	}

	/**
	 * @return string[]
	 */
	private function getVisibilityFields(): array
	{
		$fields = [];
		if (EntityTypeSettings::of($this->entityType)->isRecurringSupported())
		{
			$fields[] = 'isRecurring';
		}

		if ($this->entityType->getId() === OwnerType::COMPANY)
		{
			$fields[] = 'isMyCompany';
		}

		return $fields === [] ? ['id'] : $fields;
	}

	private function getFieldNameWithoutOperator(string $field): string
	{
		return ltrim($field, '=');
	}

	private function getProvider(): AbstractItemProvider
	{
		if ($this->provider !== null)
		{
			return $this->provider->withAccessCheck($this->userId, AccessMode::Filter);
		}

		return ItemProvider::forEntityType($this->entityType)
			->withAccessCheck($this->userId, AccessMode::Filter)
		;
	}
}

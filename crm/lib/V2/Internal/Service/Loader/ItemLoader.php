<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Loader;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Public\Entity\Item\ContactBinding;
use Bitrix\Crm\V2\Public\Entity\Item\CompanyBinding;
use Bitrix\Crm\V2\Public\Entity\Item\Company;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\Provider\Item\AccessMode;
use Bitrix\Crm\V2\Public\Provider\Item\CompanyProvider;
use Bitrix\Crm\V2\Public\Provider\Item\ItemProvider;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;

class ItemLoader
{
	private const BATCH_SIZE = 500;

	public function __construct(
		private readonly ?int $userId = null,
	)
	{
	}

	/**
	 * @param Item[] $items
	 */
	public function load(array $items, ItemSelect $select): void
	{
		if (!$select->shouldLoadRelatedCrmObjects())
		{
			return;
		}

		$relatedSelect = $select->getRelatedCrmObjectsSelect();
		if (isset($relatedSelect['myCompany']))
		{
			$this->loadMyCompanies($items, $relatedSelect['myCompany']);
		}

		$relatedFieldMap = $this->collectRelatedFieldMap($items, $select);
		if ($relatedFieldMap === [])
		{
			return;
		}

		$loadedItemsByEntityType = [];
		foreach ($this->collectIdsByEntityType($relatedFieldMap) as $entityTypeId => $ids)
		{
			$entityType = EntityType::fromId($entityTypeId);
			$loadedItemsByEntityType[$entityTypeId] = [];
			$relatedItemSelect = $this->getRelatedItemSelect($select, $entityTypeId);
			foreach (array_chunk(array_keys($ids), self::BATCH_SIZE) as $idsChunk)
			{
				foreach ($this->loadRelatedItems($entityType, $idsChunk, $relatedItemSelect) as $loadedItem)
				{
					$loadedItemId = $loadedItem->getId();
					if ($loadedItemId !== null)
					{
						$loadedItemsByEntityType[$entityTypeId][$loadedItemId] = $loadedItem;
					}
				}
			}
		}

		foreach ($relatedFieldMap as $fieldName => $relatedByOwner)
		{
			foreach ($relatedByOwner as $ownerId => $relatedDescriptor)
			{
				$value = $this->resolveLoadedRelationValue($loadedItemsByEntityType, $relatedDescriptor);
				if ($fieldName !== 'contacts' && $fieldName !== 'companies')
				{
					$value = $value[0] ?? null;
				}
				$relatedDescriptor['owner']->internalSet($fieldName, $value);
			}
		}
	}

	/** @param int[] $ids */
	protected function loadRelatedItems(EntityType $entityType, array $ids, ItemSelect $select): iterable
	{
		return ItemProvider::forEntityType($entityType)
			->withAccessCheck($this->userId, AccessMode::Restricted)
			->getByIds($ids, $select)
		;
	}

	/**
	 * @param Item[] $items
	 * @return array<string, array<int, array{owner: Item, entityTypeId: int|null, ids: int[]}>>
	 */
	private function collectRelatedFieldMap(array $items, ItemSelect $select): array
	{
		$result = [];
		foreach ($items as $item)
		{
			$ownerId = $item->getId();
			if ($ownerId === null)
			{
				continue;
			}

			foreach (array_keys($select->getRelatedCrmObjectsSelect()) as $fieldName)
			{
				if ($fieldName === 'myCompany')
				{
					continue;
				}

				[$entityTypeId, $ids] = $this->resolveRelatedIds($item, $fieldName);
				$result[$fieldName][$ownerId] = [
					'owner' => $item,
					'entityTypeId' => $entityTypeId,
					'ids' => $ids,
				];
			}
		}

		return $result;
	}

	private function getRelatedItemSelect(ItemSelect $select, int $entityTypeId): ItemSelect
	{
		$fields = ['id'];
		foreach ($select->getRelatedCrmObjectsSelect() as $relatedFieldName => $relatedSelect)
		{
			$relatedEntityTypeId = $this->resolveRelatedFieldType($relatedFieldName);
			if ($relatedEntityTypeId !== $entityTypeId)
			{
				continue;
			}

			if ($this->shouldLoadRelatedTitle($relatedSelect))
			{
				array_push($fields, ...$this->getTitleSourceFields($entityTypeId));
			}

			if ($this->shouldLoadRelatedUrl($relatedSelect))
			{
				array_push($fields, ...$this->getUrlSourceFields($entityTypeId));
			}
		}

		return new ItemSelect(...array_values(array_unique($fields)));
	}

	private function shouldLoadRelatedTitle(array $select): bool
	{
		return $select === [] || in_array('title', $select, true);
	}

	private function shouldLoadRelatedUrl(array $select): bool
	{
		return $select === [] || in_array('url', $select, true);
	}

	/**
	 * @return string[]
	 */
	private function getTitleSourceFields(int $entityTypeId): array
	{
		if ($entityTypeId === EntityType::contact()->getId())
		{
			return ['name', 'lastName', 'secondName'];
		}

		return ['title'];
	}

	/**
	 * @return string[]
	 */
	private function getUrlSourceFields(int $entityTypeId): array
	{
		if (EntityTypeSettings::of(EntityType::fromId($entityTypeId))->isCategoriesSupported())
		{
			return ['categoryId'];
		}

		return [];
	}

	private function resolveRelatedFieldType(string $fieldName): ?int
	{
		return match (true)
		{
			$fieldName === 'company' => EntityType::company()->getId(),
			$fieldName === 'lead' => EntityType::lead()->getId(),
			$fieldName === 'quote' => EntityType::quote()->getId(),
			$fieldName === 'contact' || $fieldName === 'contacts' => EntityType::contact()->getId(),
			$fieldName === 'companies' => EntityType::company()->getId(),
			str_starts_with($fieldName, 'related') => EntityType::fromCode(
				substr($fieldName, strlen('related')),
			)?->getId(),
			default => null,
		};
	}

	/**
	 * @param array<string, array<int, array{owner: Item, entityTypeId: int|null, ids: int[]}>> $relatedFieldMap
	 * @return array<int, array<int, true>>
	 */
	private function collectIdsByEntityType(array $relatedFieldMap): array
	{
		$result = [];
		foreach ($relatedFieldMap as $relatedByOwner)
		{
			foreach ($relatedByOwner as $relatedDescriptor)
			{
				$entityTypeId = $relatedDescriptor['entityTypeId'];
				if ($entityTypeId === null)
				{
					continue;
				}

				foreach ($relatedDescriptor['ids'] as $id)
				{
					if ($id > 0)
					{
						$result[$entityTypeId][$id] = true;
					}
				}
			}
		}

		return $result;
	}

	/**
	 * @return array{0: int|null, 1: int[]}
	 */
	private function resolveRelatedIds(Item $item, string $fieldName): array
	{
		if ($fieldName === 'company')
		{
			$companyId = method_exists($item, 'getCompanyId') ? (int)$item->getCompanyId() : 0;

			return [EntityType::company()->getId(), $companyId > 0 ? [$companyId] : []];
		}

		if ($fieldName === 'lead')
		{
			$leadId = method_exists($item, 'getLeadId') ? (int)$item->getLeadId() : 0;

			return [EntityType::lead()->getId(), $leadId > 0 ? [$leadId] : []];
		}

		if ($fieldName === 'quote')
		{
			$quoteId = method_exists($item, 'getQuoteId') ? (int)$item->getQuoteId() : 0;

			return [EntityType::quote()->getId(), $quoteId > 0 ? [$quoteId] : []];
		}

		if ($fieldName === 'contact' || $fieldName === 'contacts')
		{
			$contactBindings = method_exists($item, 'getContactBindings') ? $item->getContactBindings() : null;
			if ($contactBindings === null)
			{
				return [EntityType::contact()->getId(), []];
			}

			if ($fieldName === 'contact')
			{
				$primaryContactId = $contactBindings->getPrimary()?->getContactId() ?? 0;

				return [EntityType::contact()->getId(), $primaryContactId > 0 ? [$primaryContactId] : []];
			}

			$contactIds = array_map(
				static fn(ContactBinding $binding) => $binding->getContactId(),
				$contactBindings->getAll(),
			);

			return [EntityType::contact()->getId(), array_values(array_filter($contactIds, static fn(int $id) => $id > 0))];
		}

		if ($fieldName === 'companies')
		{
			$companyBindings = method_exists($item, 'getCompanyBindings') ? $item->getCompanyBindings() : null;
			if ($companyBindings === null)
			{
				return [EntityType::company()->getId(), []];
			}

			$companyIds = array_map(
				static fn(CompanyBinding $binding) => $binding->getCompanyId(),
				$companyBindings->getAll(),
			);

			return [EntityType::company()->getId(), array_values(array_filter($companyIds, static fn(int $id) => $id > 0))];
		}

		if (!str_starts_with($fieldName, 'related'))
		{
			return [null, []];
		}

		$entityCode = substr($fieldName, strlen('related'));
		$entityType = EntityType::fromCode($entityCode);
		if ($entityType === null)
		{
			return [null, []];
		}

		$relatedId = $item->getParentId($entityType) ?? 0;

		return [$entityType->getId(), $relatedId > 0 ? [$relatedId] : []];
	}

	/**
	 * @param array<int, array<int, Item>> $loadedItemsByEntityType
	 * @param array{owner: Item, entityTypeId: int|null, ids: int[]} $relatedDescriptor
	 * @return array<int, Item>
	 */
	private function resolveLoadedRelationValue(array $loadedItemsByEntityType, array $relatedDescriptor): array
	{
		$entityTypeId = $relatedDescriptor['entityTypeId'];
		if ($entityTypeId === null || $relatedDescriptor['ids'] === [])
		{
			return [];
		}

		$itemsById = $loadedItemsByEntityType[$entityTypeId] ?? [];
		$result = [];
		foreach ($relatedDescriptor['ids'] as $id)
		{
			$loadedItem = $itemsById[$id] ?? null;
			if ($loadedItem !== null)
			{
				$result[] = $loadedItem;
			}
		}

		return $result;
	}

	/**
	 * @param Item[] $items
	 * @param string[] $select
	 */
	private function loadMyCompanies(array $items, array $select): void
	{
		$myCompanyIdsByOwnerId = $this->collectMyCompanyIdsByOwnerId($items);
		$companiesById = $myCompanyIdsByOwnerId === []
			? []
			: $this->loadMyCompaniesById(
				array_values(array_unique($myCompanyIdsByOwnerId)),
				$select,
			)
		;
		$canReadBaseFields = Container::getInstance()
			->getUserPermissions($this->userId)
			->myCompany()
			->canReadBaseFields()
		;

		foreach ($items as $item)
		{
			$ownerId = $item->getId();
			if ($ownerId === null || !isset($myCompanyIdsByOwnerId[$ownerId]))
			{
				$item->internalSet('myCompany', null);

				continue;
			}

			$myCompany = $companiesById[$myCompanyIdsByOwnerId[$ownerId]] ?? null;
			if ($myCompany !== null && !$canReadBaseFields)
			{
				$myCompany->markAsRestricted();
			}

			$item->internalSet('myCompany', $myCompany);
		}
	}

	/**
	 * @param Item[] $items
	 * @return array<int, int>
	 */
	private function collectMyCompanyIdsByOwnerId(array $items): array
	{
		$result = [];
		foreach ($items as $item)
		{
			$ownerId = $item->getId();
			if ($ownerId === null || !method_exists($item, 'getMyCompanyId'))
			{
				continue;
			}

			$myCompanyId = (int)$item->getMyCompanyId();
			if ($myCompanyId > 0)
			{
				$result[$ownerId] = $myCompanyId;
			}
		}

		return $result;
	}

	/**
	 * @param int[] $ids
	 * @param string[] $select
	 * @return array<int, Company>
	 */
	private function loadMyCompaniesById(array $ids, array $select): array
	{
		$itemSelect = new ItemSelect(...$this->getMyCompanySourceFields($select));
		$result = [];
		foreach (array_chunk($ids, self::BATCH_SIZE) as $idsChunk)
		{
			$collection = (new CompanyProvider())->getByIds($idsChunk, $itemSelect);
			foreach ($collection as $company)
			{
				if (!$company instanceof Company || $company->getId() === null || $company->getIsMyCompany() !== true)
				{
					continue;
				}

				$result[(int)$company->getId()] = $company;
			}
		}

		return $result;
	}

	/**
	 * @param string[] $select
	 * @return string[]
	 */
	private function getMyCompanySourceFields(array $select): array
	{
		$fields = ['id', 'isMyCompany'];
		if ($select === [] || in_array('title', $select, true))
		{
			$fields[] = 'title';
		}
		if ($select === [] || in_array('url', $select, true))
		{
			$fields[] = 'categoryId';
		}

		return array_values(array_unique($fields));
	}
}

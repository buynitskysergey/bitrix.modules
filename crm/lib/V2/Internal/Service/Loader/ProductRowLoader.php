<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Loader;

use Bitrix\Crm\ProductRowTable;
use Bitrix\Crm\V2\Internal\Integration\Catalog\ProductDictionary;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRow;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRowCollection;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;

/**
 * Loads product rows from `b_crm_product_row` onto V2 Items that support them
 * (Deal / Lead / Quote / SmartInvoice / SmartDocument / SmartB2eDocument and SPA).
 *
 * Storage is a single shared table keyed by `(OWNER_TYPE, OWNER_ID)`, where `OWNER_TYPE`
 * is the {@see \CCrmOwnerTypeAbbr} short code (D / L / Q / SI / SD / SBD / Tn). All entity
 * types share the same loader for that reason.
 *
 * Always installs a (possibly empty) {@see ProductRowCollection} on every persisted Item passed
 * in, so Provider callers can rely on null-vs-empty as "loaded?" signal.
 *
 * @internal
 */
final class ProductRowLoader
{
	/**
	 * @param Item[] $items
	 */
	public function load(array $items, ItemSelect $select): void
	{
		if (!$select->shouldLoadProductRows())
		{
			return;
		}

		$persisted = array_values(array_filter($items, static fn(Item $i) => $i->getId() !== null));
		if (empty($persisted))
		{
			return;
		}

		$ownerEntityType = $persisted[0]->getEntityType();
		$ownerType = \CCrmOwnerTypeAbbr::ResolveByTypeID($ownerEntityType->getId());
		if ($ownerType === \CCrmOwnerTypeAbbr::Undefined)
		{
			return;
		}

		$ownerIds = array_map(static fn(Item $i) => (int)$i->getId(), $persisted);

		// Single bulk query for all owners; bucket on PHP side. Avoids N+1 across getByIds().
		$rowsByOwner = $this->fetchRowsBucketed($ownerType, $ownerIds);
		// One more query for the whole batch, for the same reason.
		$catalogNames = $this->fetchCatalogNames($rowsByOwner);

		foreach ($persisted as $item)
		{
			$collection = new ProductRowCollection();
			foreach ($rowsByOwner[(int)$item->getId()] ?? [] as $row)
			{
				$collection->add($this->buildRow($row, $ownerEntityType, $catalogNames));
			}

			$item->internalSet(Item::productRows, $collection);
		}
	}

	/**
	 * Rows are read as plain storage values on purpose. The ORM object of this table is the legacy
	 * {@see \Bitrix\Crm\ProductRow}, whose getters replace an empty product name with a name read
	 * from the catalog one row at a time - both the substitution rule and the extra query per row
	 * belong to the legacy read path, not to this one.
	 *
	 * @param int[] $ownerIds
	 * @return array<int, array<string, mixed>[]> ownerId => rows
	 */
	private function fetchRowsBucketed(string $ownerType, array $ownerIds): array
	{
		$rows = ProductRowTable::query()
			->setSelect([
				'ID',
				'OWNER_ID',
				'PRODUCT_ID',
				'PRODUCT_NAME',
				'QUANTITY',
				'PRICE',
				'DISCOUNT_TYPE_ID',
				'DISCOUNT_RATE',
				'DISCOUNT_SUM',
				'TAX_RATE',
				'TAX_NAME',
				'TAX_INCLUDED',
				'MEASURE_CODE',
				'MEASURE_NAME',
				'SORT',
				'TYPE',
			])
			->where('OWNER_TYPE', $ownerType)
			->whereIn('OWNER_ID', $ownerIds)
			->setOrder(['SORT' => 'ASC', 'ID' => 'ASC'])
			->fetchAll()
		;

		$bucket = [];
		foreach ($rows as $row)
		{
			$bucket[(int)$row['OWNER_ID']][] = $row;
		}

		return $bucket;
	}

	/**
	 * Catalog names for the rows that kept none of their own, in one lookup for every owner of the
	 * batch. Rows that carry a stored name never reach the catalog, so an item whose rows are all
	 * named costs no extra query at all.
	 *
	 * @param array<int, array<string, mixed>[]> $rowsByOwner
	 * @return array<int, string> productId => name
	 */
	private function fetchCatalogNames(array $rowsByOwner): array
	{
		$productIds = [];
		foreach ($rowsByOwner as $rows)
		{
			foreach ($rows as $row)
			{
				if ($row['PRODUCT_NAME'] !== null)
				{
					continue;
				}

				$productId = (int)$row['PRODUCT_ID'];
				if ($productId > 0)
				{
					$productIds[$productId] = $productId;
				}
			}
		}

		if (empty($productIds))
		{
			return [];
		}

		return (new ProductDictionary())->getNames(array_values($productIds));
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<int, string> $catalogNames
	 */
	private function buildRow(array $row, EntityType $ownerEntityType, array $catalogNames): ProductRow
	{
		$productRow = (new ProductRow())
			->setId((int)$row['ID'])
			->setQuantity((float)$row['QUANTITY'])
			->setPrice((float)$row['PRICE'])
			->setDiscount((float)($row['DISCOUNT_SUM'] ?? 0.0))
			->setSort((int)($row['SORT'] ?? 0))
			->setOwnerEntityType($ownerEntityType)
		;

		// PRODUCT_ID is nullable in the schema (cascade detach); skip when absent so
		// we don't blow up with a TypeError on the int-only setter. The same holds for
		// every other nullable column below.
		if ($row['PRODUCT_ID'] !== null)
		{
			$productRow->setProductId((int)$row['PRODUCT_ID']);
		}

		$name = $this->resolveName($row, $catalogNames);
		if ($name !== null)
		{
			$productRow->setName($name);
		}

		if ($row['OWNER_ID'] !== null)
		{
			$productRow->setOwnerId((int)$row['OWNER_ID']);
		}

		if ($row['DISCOUNT_TYPE_ID'] !== null)
		{
			$productRow->setDiscountTypeId((int)$row['DISCOUNT_TYPE_ID']);
		}

		if ($row['DISCOUNT_RATE'] !== null)
		{
			$productRow->setDiscountRate((float)$row['DISCOUNT_RATE']);
		}

		if ($row['TAX_RATE'] !== null)
		{
			$productRow->setTaxRate((float)$row['TAX_RATE']);
		}

		if ($row['TAX_NAME'] !== null)
		{
			$productRow->setTaxName((string)$row['TAX_NAME']);
		}

		if ($row['TAX_INCLUDED'] !== null)
		{
			$productRow->setTaxIncluded($row['TAX_INCLUDED'] === 'Y');
		}

		if ($row['MEASURE_CODE'] !== null)
		{
			$productRow->setMeasureCode((int)$row['MEASURE_CODE']);
		}

		if ($row['MEASURE_NAME'] !== null)
		{
			$productRow->setMeasureName((string)$row['MEASURE_NAME']);
		}

		if ($row['TYPE'] !== null)
		{
			$productRow->setProductTypeId((int)$row['TYPE']);
		}

		return $productRow;
	}

	/**
	 * The catalog fills in a name the row never stored. A stored empty string is a name the user
	 * chose to leave blank, so it survives the read untouched.
	 *
	 * @param array<string, mixed> $row
	 * @param array<int, string> $catalogNames
	 */
	private function resolveName(array $row, array $catalogNames): ?string
	{
		if ($row['PRODUCT_NAME'] !== null)
		{
			return (string)$row['PRODUCT_NAME'];
		}

		return $catalogNames[(int)$row['PRODUCT_ID']] ?? null;
	}
}

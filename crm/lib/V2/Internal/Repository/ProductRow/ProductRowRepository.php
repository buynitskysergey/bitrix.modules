<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\ProductRow;

use Bitrix\Crm\ProductRowTable;
use Bitrix\Crm\V2\Internal\Integration\Catalog\ProductDictionary;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRow;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRowCollection;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\Param\ProductRowFilter;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\Param\ProductRowSort;
use Bitrix\Main\Provider\Params\PagerInterface;

/**
 * The single point the new layer reads `b_crm_product_row` through. Product rows are read here as
 * standalone records addressed by their own primary key - {@see \Bitrix\Crm\V2\Internal\Service\Loader\ProductRowLoader}
 * stays in place for the other direction (filling the collection of an already loaded Item) and both
 * use the same table as their storage boundary.
 *
 * Returns public {@see ProductRow} / {@see ProductRowCollection}; ORM objects never leave the class.
 *
 * Read-only by design: rows are written exclusively through the update operation of their owner.
 *
 * A row whose owner no longer exists is returned like any other - deciding it is unavailable belongs
 * to the Provider, not to storage. Such rows do exist: the cascade deliberately does not follow the
 * owner into the recycle bin.
 *
 * @internal
 */
final class ProductRowRepository
{
	private const SELECT = [
		'ID',
		'OWNER_ID',
		'OWNER_TYPE',
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
	];

	private const ORM_COLUMN_BY_SORT_FIELD = [
		ProductRowSort::FIELD_SORT => 'SORT',
		ProductRowSort::FIELD_ID => 'ID',
	];

	public function findById(int $id): ?ProductRow
	{
		$row = ProductRowTable::query()
			->setSelect(self::SELECT)
			->where('ID', $id)
			->setLimit(1)
			->fetch()
		;
		if (!$row)
		{
			return null;
		}

		return $this->buildRow(
			$row,
			self::resolveOwnerEntityType((string)$row['OWNER_TYPE']),
			$this->fetchCatalogNames([$row]),
		);
	}

	/**
	 * Rows of one owner, in the requested order, with the page applied in SQL. With an owner given,
	 * the `OWNER_ID + OWNER_TYPE + SORT` index covers both the selection and the row order.
	 */
	public function findAllByOwner(
		EntityType $ownerType,
		ProductRowFilter $filter,
		?ProductRowSort $sort = null,
		?PagerInterface $pager = null,
	): ProductRowCollection
	{
		$collection = new ProductRowCollection();

		$ormFilter = self::buildFilter($ownerType, $filter);
		if ($ormFilter === null)
		{
			return $collection;
		}

		$query = ProductRowTable::query()
			->setSelect(self::SELECT)
			->setFilter($ormFilter)
			->setOrder(self::resolveOrder($sort))
		;
		if ($pager !== null)
		{
			$query->setLimit($pager->getLimit());
			$query->setOffset($pager->getOffset());
		}

		$rows = $query->fetchAll();
		$catalogNames = $this->fetchCatalogNames($rows);
		foreach ($rows as $row)
		{
			$collection->add($this->buildRow($row, $ownerType, $catalogNames));
		}

		return $collection;
	}

	/**
	 * The domain owner type behind the stored short code. `null` for a code that is not an Item type
	 * at all - a suspended (recycle bin) code, or a non-Item CRM object.
	 */
	private static function resolveOwnerEntityType(string $ownerAbbr): ?EntityType
	{
		$ownerTypeId = \CCrmOwnerTypeAbbr::ResolveTypeID($ownerAbbr);

		return EntityType::isValid($ownerTypeId) ? EntityType::fromId($ownerTypeId) : null;
	}

	/**
	 * The storage keys rows by the {@see \CCrmOwnerTypeAbbr} short code, so the domain owner type is
	 * translated here and nowhere else.
	 *
	 * @return array<string, mixed>|null `null` when the filter can match no row at all (unknown owner
	 *         type, or an explicitly empty set of ids), so the caller skips the query entirely.
	 */
	private static function buildFilter(EntityType $ownerType, ProductRowFilter $filter): ?array
	{
		$ownerAbbr = \CCrmOwnerTypeAbbr::ResolveByTypeID($ownerType->getId());
		if ($ownerAbbr === \CCrmOwnerTypeAbbr::Undefined)
		{
			return null;
		}

		$ormFilter = [
			'=OWNER_TYPE' => $ownerAbbr,
			'=OWNER_ID' => $filter->getOwnerId(),
		];

		$ids = $filter->getIds();
		if ($ids !== null)
		{
			if ($ids === [])
			{
				return null;
			}

			$ormFilter['@ID'] = $ids;
		}

		$productIds = $filter->getProductIds();
		if ($productIds !== null)
		{
			if ($productIds === [])
			{
				return null;
			}

			$ormFilter['@PRODUCT_ID'] = $productIds;
		}

		return $ormFilter;
	}

	/**
	 * @return array<string, string> ORM-name keyed ASC/DESC map, never empty: {@see ProductRowSort}
	 *         always carries the primary-key tie-breaker.
	 */
	private static function resolveOrder(?ProductRowSort $sort): array
	{
		$order = [];
		foreach (($sort ?? new ProductRowSort())->getRaw() as $field => $direction)
		{
			$column = self::ORM_COLUMN_BY_SORT_FIELD[$field] ?? null;
			if ($column !== null)
			{
				$order[$column] = $direction;
			}
		}

		return $order;
	}

	/**
	 * Catalog names for the rows that kept none of their own, in a single lookup for the whole batch.
	 * Mirrors the substitution rule of the loader path: a stored empty string is a name the user chose
	 * to leave blank and survives the read untouched.
	 *
	 * @param array<string, mixed>[] $rows
	 * @return array<int, string> productId => name
	 */
	private function fetchCatalogNames(array $rows): array
	{
		$productIds = [];
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

		if (empty($productIds))
		{
			return [];
		}

		return (new ProductDictionary())->getNames(array_values($productIds));
	}

	/**
	 * Rows are read as plain storage values on purpose. The ORM object of this table is the legacy
	 * {@see \Bitrix\Crm\ProductRow}, whose getters read a missing product name from the catalog one
	 * row at a time.
	 *
	 * @param array<string, mixed> $row
	 * @param array<int, string> $catalogNames
	 */
	private function buildRow(array $row, ?EntityType $ownerEntityType, array $catalogNames): ProductRow
	{
		$productRow = (new ProductRow())
			->setId((int)$row['ID'])
			->setQuantity((float)$row['QUANTITY'])
			->setPrice((float)$row['PRICE'])
			->setDiscount((float)($row['DISCOUNT_SUM'] ?? 0.0))
			->setSort((int)($row['SORT'] ?? 0))
		;

		if ($ownerEntityType !== null)
		{
			$productRow->setOwnerEntityType($ownerEntityType);
		}

		// PRODUCT_ID is nullable in the schema (cascade detach); skip when absent so we don't blow up
		// with a TypeError on the int-only setter. The same holds for every other nullable column below.
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
	 * The catalog fills in a name the row never stored.
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

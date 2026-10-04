<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Catalog;

use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\MeasureTable;
use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * The single point CRM V2 reads the product catalog through.
 *
 * The catalog module is an optional dependency of CRM: with it absent every lookup answers
 * "nothing is known about these products". That is not an error - legacy behaves the same way and
 * simply leaves the name and the type of a product row alone.
 *
 * {@see getPricesAndSettings()} is the exception and says so itself: a card of the catalog has no price
 * and no currency to answer with when the module is gone, so it requires the module instead of
 * tolerating its absence.
 *
 * @internal
 */
class ProductDictionary
{
	/** Key of the catalog name in a {@see getNamesAndTypes()} entry. */
	public const KEY_NAME = 'name';

	/** Key of the {@see \Bitrix\Crm\ProductType} value in a {@see getNamesAndTypes()} entry. */
	public const KEY_TYPE_ID = 'typeId';

	/** Key of the base price in a {@see getPricesAndSettings()} entry. */
	public const KEY_PRICE = 'price';

	/** Key of the currency of the base price in a {@see getPricesAndSettings()} entry. */
	public const KEY_CURRENCY_ID = 'currencyId';

	/** Key of the VAT rate reference in a {@see getPricesAndSettings()} entry. */
	public const KEY_VAT_ID = 'vatId';

	/** Key of the "VAT is part of the price" flag in a {@see getPricesAndSettings()} entry. */
	public const KEY_VAT_INCLUDED = 'vatIncluded';

	/** Key of the measure classifier code in a {@see getPricesAndSettings()} entry. */
	public const KEY_MEASURE_CODE = 'measureCode';

	/**
	 * Catalog names of the given products, keyed by product id. A product the catalog does not know
	 * is absent from the result, so the caller can tell "no such product" from "an empty name".
	 *
	 * @param int[] $productIds
	 * @return array<int, string>
	 */
	public function getNames(array $productIds): array
	{
		$names = [];
		foreach ($this->getNamesAndTypes($productIds) ?? [] as $productId => $product)
		{
			if ($product[self::KEY_NAME] !== null)
			{
				$names[$productId] = $product[self::KEY_NAME];
			}
		}

		return $names;
	}

	/**
	 * Name and product type of the given products in a single lookup, keyed by product id. A product
	 * the catalog does not know is absent from the result, so membership doubles as the existence
	 * check and no second query is needed to learn the type.
	 *
	 * @param int[] $productIds
	 * @return array<int, array{name: string|null, typeId: int|null}>|null `null` when the catalog is
	 *         unavailable: nothing is known about any product, which is not the same answer as
	 *         "no such product".
	 */
	public function getNamesAndTypes(array $productIds): ?array
	{
		$productIds = $this->filterExistingIds($productIds);
		if (empty($productIds))
		{
			return [];
		}

		if (!$this->isProductCatalogAvailable())
		{
			return null;
		}

		$products = [];
		$iterator = ProductTable::getList([
			'select' => [
				'ID',
				'PRODUCT_NAME' => 'IBLOCK_ELEMENT.NAME',
				'TYPE',
			],
			'filter' => [
				'@ID' => $productIds,
			],
		]);
		while ($row = $iterator->fetch())
		{
			$products[(int)$row['ID']] = [
				self::KEY_NAME => $row['PRODUCT_NAME'] === null ? null : (string)$row['PRODUCT_NAME'],
				self::KEY_TYPE_ID => $row['TYPE'] === null ? null : (int)$row['TYPE'],
			];
		}

		return $products;
	}

	/**
	 * Price, currency, tax and measure of the given products - everything about a catalog card that the
	 * iblock element does not keep.
	 *
	 * The whole batch costs a fixed number of queries: one for the prices, one for the product settings
	 * and at most one for the measure classifier. Reading a page card by card would cost three queries
	 * per card instead, which is exactly the shape of read the legacy list has.
	 *
	 * Every requested product gets an entry with all five keys, so the caller never has to tell an
	 * absent key from an unknown value: a product the catalog knows nothing about answers with the empty
	 * values of the entry.
	 *
	 * @param int[] $productIds
	 * @return array<int, array{price: float|null, currencyId: string|null, vatId: int|null,
	 *         vatIncluded: bool, measureCode: int|null}> keyed by product id.
	 * @throws \Bitrix\Main\LoaderException the catalog module is mandatory here, unlike everywhere else
	 *         in this class: without it there is no price and no currency to answer with at all.
	 */
	public function getPricesAndSettings(array $productIds): array
	{
		$productIds = $this->filterExistingIds($productIds);
		if (empty($productIds))
		{
			return [];
		}

		Loader::requireModule('catalog');

		$settings = array_fill_keys($productIds, [
			self::KEY_PRICE => null,
			self::KEY_CURRENCY_ID => null,
			self::KEY_VAT_ID => null,
			self::KEY_VAT_INCLUDED => false,
			self::KEY_MEASURE_CODE => null,
		]);

		$this->fillPrices($settings, $productIds);
		$this->fillProductSettings($settings, $productIds);

		return $settings;
	}

	/**
	 * The base price of every product in one query. A price type may hold several rows differing by the
	 * quantity range; the row of the lowest range is the base one, which is the row the legacy read of a
	 * single product takes as well.
	 *
	 * @param array<int, array<string, mixed>> $settings
	 * @param int[] $productIds
	 */
	private function fillPrices(array &$settings, array $productIds): void
	{
		$priceTypeId = $this->getPriceTypeId();
		if ($priceTypeId <= 0)
		{
			return;
		}

		$iterator = PriceTable::getList([
			'select' => ['PRODUCT_ID', 'PRICE', 'CURRENCY'],
			'filter' => [
				'@PRODUCT_ID' => $productIds,
				'=CATALOG_GROUP_ID' => $priceTypeId,
			],
			'order' => [
				'PRODUCT_ID' => 'ASC',
				'QUANTITY_FROM' => 'ASC',
				'QUANTITY_TO' => 'ASC',
			],
		]);
		$filled = [];
		while ($row = $iterator->fetch())
		{
			$productId = (int)$row['PRODUCT_ID'];
			if (isset($filled[$productId]) || !isset($settings[$productId]))
			{
				continue;
			}

			$filled[$productId] = true;
			$settings[$productId][self::KEY_PRICE] = $row['PRICE'] === null ? null : (float)$row['PRICE'];
			$settings[$productId][self::KEY_CURRENCY_ID] =
				$row['CURRENCY'] === null ? null : (string)$row['CURRENCY']
			;
		}
	}

	/**
	 * Tax and measure of every product in one query, plus one lookup that turns the measure records the
	 * products point at into the classifier codes the contract publishes.
	 *
	 * @param array<int, array<string, mixed>> $settings
	 * @param int[] $productIds
	 */
	private function fillProductSettings(array &$settings, array $productIds): void
	{
		$productIdsByMeasureId = [];
		$iterator = ProductTable::getList([
			'select' => ['ID', 'VAT_ID', 'VAT_INCLUDED', 'MEASURE'],
			'filter' => ['@ID' => $productIds],
		]);
		while ($row = $iterator->fetch())
		{
			$productId = (int)$row['ID'];
			if (!isset($settings[$productId]))
			{
				continue;
			}

			// A zero rate reference means the same as no reference at all, and the contract has one way
			// of saying that.
			$vatId = (int)($row['VAT_ID'] ?? 0);
			$settings[$productId][self::KEY_VAT_ID] = $vatId > 0 ? $vatId : null;
			// A row of the catalog carries the stored letter even for a field the ORM declares boolean, and
			// every letter of it - 'N' included - is a non-empty string.
			$settings[$productId][self::KEY_VAT_INCLUDED] = $row['VAT_INCLUDED'] === 'Y';

			$measureId = (int)($row['MEASURE'] ?? 0);
			if ($measureId > 0)
			{
				$productIdsByMeasureId[$measureId][] = $productId;
			}
		}

		if (empty($productIdsByMeasureId))
		{
			return;
		}

		$codes = $this->getMeasureCodes(array_keys($productIdsByMeasureId));
		foreach ($productIdsByMeasureId as $measureId => $measureProductIds)
		{
			foreach ($measureProductIds as $productId)
			{
				$settings[$productId][self::KEY_MEASURE_CODE] = $codes[$measureId] ?? null;
			}
		}
	}

	/**
	 * @param int[] $measureIds
	 * @return array<int, int> measure record id => classifier code
	 */
	private function getMeasureCodes(array $measureIds): array
	{
		$codes = [];
		$iterator = MeasureTable::getList([
			'select' => ['ID', 'CODE'],
			'filter' => ['@ID' => $measureIds],
		]);
		while ($row = $iterator->fetch())
		{
			if ($row['CODE'] !== null)
			{
				$codes[(int)$row['ID']] = (int)$row['CODE'];
			}
		}

		return $codes;
	}

	/**
	 * The price type CRM shows on a card: the one the portal chose, and the base type of the catalog
	 * while no valid choice is stored. The legacy read persists the fallback back into the option; a
	 * read of a list has no business writing a setting, so this one does not.
	 */
	private function getPriceTypeId(): int
	{
		$priceTypeId = (int)Option::get('crm', 'selected_catalog_group_id');
		if ($priceTypeId > 0)
		{
			$priceTypes = GroupTable::getTypeList();
			if (isset($priceTypes[$priceTypeId]))
			{
				return $priceTypeId;
			}
		}

		return (int)(GroupTable::getBasePriceTypeId() ?? 0);
	}

	protected function isProductCatalogAvailable(): bool
	{
		return Loader::includeModule('catalog') && class_exists('\\Bitrix\\Catalog\\ProductTable');
	}

	/**
	 * @param int[] $productIds
	 * @return int[]
	 */
	private function filterExistingIds(array $productIds): array
	{
		return array_values(array_unique(array_filter(
			$productIds,
			static fn(int $productId): bool => $productId > 0,
		)));
	}
}

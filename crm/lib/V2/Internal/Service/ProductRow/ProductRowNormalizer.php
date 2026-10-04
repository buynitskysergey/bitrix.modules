<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\ProductRow;

use Bitrix\Crm\V2\Internal\Integration\Catalog\ProductDictionary;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRow;
use Bitrix\Main\Result;

/**
 * Turns the fields a client sent for a product row into the field set the owner update pipeline is
 * given, or refuses the input.
 *
 * Speaks the public field names of {@see ProductRow} on the way in and the storage columns of
 * `b_crm_product_row` on the way out - the whitelist and the name map are one and the same table,
 * so a field that has no storage column simply cannot be written.
 *
 * Three steps, in this order:
 *
 * 1. **whitelist** - an unknown or read-only field is rejected with a validation error instead of
 *    being dropped in silence, uniformly for every write scenario;
 * 2. **tax rate rule** - {@see resolveTaxRate()}, the one place this group states it: `0` and `"0"`
 *    mean a zero rate, `0.0` means no rate at all;
 * 3. **catalog** - the product type is always written from the catalog and is never accepted from
 *    the client, the name is filled in only when the row carries none. The product is checked to
 *    exist, which the legacy path never did. With the catalog module absent nothing is filled in and
 *    nothing is checked, and that is not an error.
 *
 * {@see normalize()} serves a single-row write, {@see normalizeAll()} serves the replacement of the
 * whole set and asks the catalog once for the entire set rather than row by row.
 *
 * @internal
 */
final class ProductRowNormalizer
{
	/** Result data key of {@see normalize()} - one storage-shaped field set. */
	public const DATA_FIELDS = 'fields';

	/** Result data key of {@see normalizeAll()} - storage-shaped field sets under the input keys. */
	public const DATA_ROWS = 'rows';

	/**
	 * The writable fields: public name => storage column. Derived from the legacy whitelist
	 * (`\CCrmProductRow::GetFieldsInfo()` minus the read-only and hidden ones, minus the derived
	 * prices) narrowed down to what the public type actually exposes.
	 */
	private const STORAGE_COLUMNS = [
		'productId' => 'PRODUCT_ID',
		'name' => 'PRODUCT_NAME',
		'quantity' => 'QUANTITY',
		'price' => 'PRICE',
		'discount' => 'DISCOUNT_SUM',
		'taxRate' => 'TAX_RATE',
		'taxName' => 'TAX_NAME',
		'taxIncluded' => 'TAX_INCLUDED',
		'discountTypeId' => 'DISCOUNT_TYPE_ID',
		'discountRate' => 'DISCOUNT_RATE',
		'measureCode' => 'MEASURE_CODE',
		'measureName' => 'MEASURE_NAME',
		'sort' => 'SORT',
	];

	/**
	 * Known fields a client may not write: the address of the row, which the request carries by
	 * itself, and the product type, which the catalog decides.
	 */
	private const READ_ONLY_FIELDS = ['id', 'ownerId', 'ownerEntityType', 'productTypeId'];

	private const FIELD_TAX_RATE = 'taxRate';
	private const FIELD_TAX_INCLUDED = 'taxIncluded';

	private const COLUMN_PRODUCT_ID = 'PRODUCT_ID';
	private const COLUMN_PRODUCT_NAME = 'PRODUCT_NAME';
	private const COLUMN_TAX_RATE = 'TAX_RATE';
	private const COLUMN_PRODUCT_TYPE = 'TYPE';

	public function __construct(
		private readonly ProductDictionary $catalog = new ProductDictionary(),
	)
	{
	}

	/**
	 * One row of a single-row write.
	 *
	 * @param array<string, mixed> $fields public field names => values
	 * @return Result successful with {@see DATA_FIELDS}, a storage-shaped field set.
	 */
	public function normalize(array $fields): Result
	{
		$result = new Result();

		$row = $this->whitelist($fields, null, $result);
		if ($row === null)
		{
			return $result;
		}

		$row = self::applyTaxRateRule($fields, $row);

		$rows = $this->resolveFromCatalog([$row], $result, withRowIndex: false);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$row = $rows[0];
		if ($row === [])
		{
			return $result->addError(ProductRowErrorCode::emptyFields());
		}

		return $result->setData([self::DATA_FIELDS => $row]);
	}

	/**
	 * Every row of a whole set, with one catalog lookup for the set. A row left without a single
	 * writable field fails the request instead of silently dropping out of the set - dropping out
	 * would mean deleting that row.
	 *
	 * @param array<int|string, array<string, mixed>> $rows
	 * @return Result successful with {@see DATA_ROWS}, storage-shaped field sets under the input keys.
	 */
	public function normalizeAll(array $rows): Result
	{
		$result = new Result();

		$prepared = [];
		foreach ($rows as $index => $fields)
		{
			$row = $this->whitelist($fields, $index, $result);
			if ($row === null)
			{
				continue;
			}

			if ($row === [])
			{
				$result->addError(ProductRowErrorCode::rowWithoutWritableFields($index));

				continue;
			}

			$prepared[$index] = self::applyTaxRateRule($fields, $row);
		}

		if (!$result->isSuccess())
		{
			return $result;
		}

		$prepared = $this->resolveFromCatalog($prepared, $result, withRowIndex: true);

		return $result->isSuccess() ? $result->setData([self::DATA_ROWS => $prepared]) : $result;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>|null storage-shaped field set, `null` when a field was rejected.
	 */
	private function whitelist(array $fields, int|string|null $rowIndex, Result $result): ?array
	{
		$row = [];
		$isRejected = false;

		foreach ($fields as $field => $value)
		{
			$field = (string)$field;

			if (in_array($field, self::READ_ONLY_FIELDS, true))
			{
				$result->addError(ProductRowErrorCode::readOnlyField($field, $rowIndex));
				$isRejected = true;

				continue;
			}

			$column = self::STORAGE_COLUMNS[$field] ?? null;
			if ($column === null)
			{
				$result->addError(ProductRowErrorCode::unknownField($field, $rowIndex));
				$isRejected = true;

				continue;
			}

			$row[$column] = self::toStorageValue($field, $value);
		}

		return $isRejected ? null : $row;
	}

	/**
	 * The rate as it is stored, out of the value a client sent: a rate above zero, the integer `0` and a
	 * string starting with `'0'` become a number, everything else - the float `0.0` among it - becomes
	 * "no rate". Carried over from `\Bitrix\Crm\Controller\Item\ProductRow::prepareForSave()` verbatim,
	 * and public because that facade applies the very same rule while binding an added row and must not
	 * hold a second copy of it.
	 *
	 * Only a scalar is the rule's business. A value of any other shape is the caller's to judge -
	 * {@see applyTaxRateRule()} answers it with no rate, the legacy facade keeps casting it as it always has.
	 */
	public static function resolveTaxRate(mixed $taxRate): ?float
	{
		$startsWithZero = is_string($taxRate) && isset($taxRate[0]) && $taxRate[0] === '0';

		return ((float)$taxRate > 0 || $taxRate === 0 || $startsWithZero) ? (float)$taxRate : null;
	}

	/**
	 * Applies {@see resolveTaxRate()} to the raw value rather than to the whitelisted one, as the legacy
	 * facade does.
	 *
	 * @param array<string, mixed> $fields raw input
	 * @param array<string, mixed> $row storage-shaped field set
	 * @return array<string, mixed>
	 */
	private static function applyTaxRateRule(array $fields, array $row): array
	{
		if (!isset($fields[self::FIELD_TAX_RATE]))
		{
			return $row;
		}

		$taxRate = $fields[self::FIELD_TAX_RATE];
		$row[self::COLUMN_TAX_RATE] = is_scalar($taxRate) ? self::resolveTaxRate($taxRate) : null;

		return $row;
	}

	/**
	 * Writes the product type in and fills the missing name from the catalog, in a single lookup for
	 * every row given. A product the catalog does not know fails the request - the legacy path never
	 * checked the id at all.
	 *
	 * @param array<int|string, array<string, mixed>> $rows
	 * @param bool $withRowIndex whether the keys of $rows are row indexes worth reporting in errors -
	 *        a single-row write has none.
	 * @return array<int|string, array<string, mixed>>
	 */
	private function resolveFromCatalog(array $rows, Result $result, bool $withRowIndex): array
	{
		$productIds = [];
		foreach ($rows as $row)
		{
			$productId = (int)($row[self::COLUMN_PRODUCT_ID] ?? 0);
			if ($productId > 0)
			{
				$productIds[$productId] = $productId;
			}
		}

		if ($productIds === [])
		{
			return $rows;
		}

		$products = $this->catalog->getNamesAndTypes(array_values($productIds));
		if ($products === null)
		{
			return $rows;
		}

		foreach ($rows as $index => $row)
		{
			$productId = (int)($row[self::COLUMN_PRODUCT_ID] ?? 0);
			if ($productId <= 0)
			{
				continue;
			}

			$product = $products[$productId] ?? null;
			if ($product === null)
			{
				$result->addError(
					ProductRowErrorCode::productNotFound($productId, $withRowIndex ? $index : null),
				);

				continue;
			}

			if ($product[ProductDictionary::KEY_TYPE_ID] !== null)
			{
				$rows[$index][self::COLUMN_PRODUCT_TYPE] = $product[ProductDictionary::KEY_TYPE_ID];
			}

			$rows[$index][self::COLUMN_PRODUCT_NAME] ??= $product[ProductDictionary::KEY_NAME];
		}

		return $rows;
	}

	/**
	 * The public type states the flag as a boolean while storage keeps the legacy `Y`/`N`; every
	 * other field goes into its column as it came.
	 */
	private static function toStorageValue(string $field, mixed $value): mixed
	{
		if ($field === self::FIELD_TAX_INCLUDED && is_bool($value))
		{
			return $value ? 'Y' : 'N';
		}

		return $value;
	}
}

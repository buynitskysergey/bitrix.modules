<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\V2\Internal\Integration\Catalog\ProductDictionary;
use Bitrix\Main\NotSupportedException;

/**
 * The catalog products that exist, among the ones asked about.
 *
 * Zero and below is not a product at all - a product row carrying no catalog product is a legitimate
 * row - so such values pass through unchecked: the positive-number rule belongs to the owner id, not
 * to every field whose name ends in `Id`.
 *
 * With the catalog module absent nothing is known about any product and nothing is checked, which is
 * how the write scenario behaves as well: a module that is not installed is not an error of the
 * request.
 *
 * @internal
 */
class CatalogProductIdProvider implements ValidValuesProviderInterface
{
	/**
	 * @return int[]
	 */
	public static function getValidValues(Context $context): array
	{
		if ($context->isShowValues()) // Show all IDs from DB table can impact performance
		{
			throw new NotSupportedException();
		}

		$productIds = array_map('intval', (array)$context->getValue());
		$catalogIds = array_values(array_filter($productIds, static fn(int $id): bool => $id > 0));
		if ($catalogIds === [])
		{
			return $productIds;
		}

		$products = static::getDictionary()->getNamesAndTypes($catalogIds);
		if ($products === null)
		{
			return $productIds;
		}

		return array_merge(
			array_values(array_filter($productIds, static fn(int $id): bool => $id <= 0)),
			array_keys($products),
		);
	}

	protected static function getDictionary(): ProductDictionary
	{
		return new ProductDictionary();
	}
}

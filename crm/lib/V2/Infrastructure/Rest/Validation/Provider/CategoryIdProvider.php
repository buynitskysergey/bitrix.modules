<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\V2\Internal\Service\Item\Dictionary\CategoryDictionary;
use Bitrix\Crm\V2\Public\EntityType;

/**
 * @internal
 */
class CategoryIdProvider implements ValidValuesProviderInterface
{
	/**
	 * @return int[]
	 */
	public static function getValidValues(Context $context): array
	{
		static $categoriesIdMap = [];

		$userId = $context->getUserId();
		$entityType = $context->getEntityType();
		$entityTypeId = $entityType->getId();
		if (!isset($categoriesIdMap[$userId][$entityTypeId]))
		{
			$categoriesIdMap[$userId][$entityTypeId] = static::getDictionary()->getAllId($entityType, $userId);
		}

		return $categoriesIdMap[$userId][$entityTypeId];
	}

	protected static function getDictionary(): CategoryDictionary
	{
		return new CategoryDictionary();
	}
}

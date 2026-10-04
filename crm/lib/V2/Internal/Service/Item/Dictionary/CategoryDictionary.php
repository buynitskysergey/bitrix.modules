<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Dictionary;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;

/**
 * @internal
 */
class CategoryDictionary
{
	/**
	 * @return int[]
	 */
	public function getAllId(EntityType $entityType, int $userId): array
	{
		if ($userId <= 0 || !EntityTypeSettings::of($entityType)->hasCategories())
		{
			return [];
		}

		return Container::getInstance()
			->getUserPermissions($userId)
			->category()
			->getAvailableForReadingCategoriesIds($entityType->getId());
		;
	}
}

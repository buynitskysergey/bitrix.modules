<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Dictionary;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Public\EntityType;

/**
 * @internal
 */
class StageDictionary
{
	/**
	 * @return string[]
	 */
	public function getAllIdForEntityType(EntityType $entityType, int $categoryId, int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$factory = Container::getInstance()->getFactory($entityType->getId());
		if (!$factory || !$factory->isStagesSupported())
		{
			return [];
		}

		$availableStages = Container::getInstance()
			->getUserPermissions($userId)
			->stage()
			->filterAvailableForReadingStages(
				$entityType->getId(),
				$factory->getStages($categoryId)->getAll(),
				$categoryId,
			)
		;

		return array_map(
			static fn($stage): string => $stage->getStatusId(),
			$availableStages,
		);
	}
}

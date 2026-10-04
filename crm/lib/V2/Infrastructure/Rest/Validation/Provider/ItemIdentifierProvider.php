<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ItemIdentifierDto;
use Bitrix\Crm\V2\Internal\Service\Item\Dictionary\ItemDictionary;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\NotSupportedException;

/**
 * @internal
 */
class ItemIdentifierProvider implements ValidValuesProviderInterface
{
	/**
	 * @return array<int, int[]>
	 */
	public static function getValidValues(Context $context): array
	{
		if ($context->isShowValues()) // Show all IDs from DB table can impact performance
		{
			throw new NotSupportedException();
		}

		$userId = $context->getUserId();
		$entityTypeIds = $context->getExtraArgs()['entityTypeIds'] ?? [];
		$filteredIdsMapByEntityType = static::getDictionary()->getAllIdMapByEntityType(
			$userId,
			static::getFilterEntityMap($context, $entityTypeIds),
		);
		// array_merge with keeping numeric keys to display valid entityTypeId list
		$filteredIdsMapByEntityType += array_fill_keys($entityTypeIds, []);

		return $filteredIdsMapByEntityType;
	}

	protected static function getDictionary(): ItemDictionary
	{
		return new ItemDictionary();
	}

	protected static function getFilterEntityMap(Context $context, array $validEntityTypeIds): array
	{
		$filterEntityMap = [];
		$value = $context->getValue();
		if (!is_iterable($value))
		{
			$value = [$value];
		}

		foreach ($value as $itemId)
		{
			if (!$itemId instanceof ItemIdentifierDto || !isset($itemId->entityTypeId, $itemId->entityId))
			{
				continue;
			}
			if (in_array($itemId->entityTypeId, $validEntityTypeIds, true))
			{
				$filterEntityMap[$itemId->entityTypeId][] = $itemId->entityId;
			}
		}

		return $filterEntityMap;
	}
}

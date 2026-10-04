<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public;

/**
 * Factory for creating ItemId from various sources.
 * Keeps ItemId itself as a clean value object.
 */
class ItemIdFactory
{
	/**
	 * Creates ItemId from an array with various key formats.
	 * Supports: ENTITY_TYPE_ID/ENTITY_ID, OWNER_TYPE_ID/OWNER_ID, entityTypeId/entityId.
	 * Returns null if the array doesn't contain valid data.
	 */
	public static function fromArray(array $data): ?ItemId
	{
		$entityTypeId = 0;
		$entityId = 0;

		if (isset($data['ENTITY_TYPE_ID'], $data['ENTITY_ID']))
		{
			$entityTypeId = (int)$data['ENTITY_TYPE_ID'];
			$entityId = (int)$data['ENTITY_ID'];
		}
		elseif (isset($data['OWNER_TYPE_ID'], $data['OWNER_ID']))
		{
			$entityTypeId = (int)$data['OWNER_TYPE_ID'];
			$entityId = (int)$data['OWNER_ID'];
		}
		elseif (isset($data['entityTypeId'], $data['entityId']))
		{
			$entityTypeId = (int)$data['entityTypeId'];
			$entityId = (int)$data['entityId'];
		}

		if ($entityTypeId <= 0 || $entityId <= 0 || !EntityType::isValid($entityTypeId))
		{
			return null;
		}

		$categoryId = $data['CATEGORY_ID'] ?? $data['categoryId'] ?? null;
		if ($categoryId !== null)
		{
			$categoryId = (int)$categoryId;
		}

		return new ItemId(EntityType::fromId($entityTypeId), $entityId, $categoryId);
	}

	/**
	 * Creates ItemId from explicit parameters.
	 * Returns null if parameters are invalid (non-positive entityId, non-Item entity type).
	 */
	public static function fromParams(int $entityTypeId, int $entityId, ?int $categoryId = null): ?ItemId
	{
		if ($entityId <= 0 || !EntityType::isValid($entityTypeId))
		{
			return null;
		}

		return new ItemId(EntityType::fromId($entityTypeId), $entityId, $categoryId);
	}

	/**
	 * Creates ItemId from a legacy ItemIdentifier.
	 */
	public static function fromIdentifier(\Bitrix\Crm\ItemIdentifier $identifier): ItemId
	{
		return new ItemId(
			EntityType::fromId($identifier->getEntityTypeId()),
			$identifier->getEntityId(),
			$identifier->getCategoryId(),
		);
	}
}

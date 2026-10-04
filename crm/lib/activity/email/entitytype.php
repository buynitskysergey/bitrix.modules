<?php

declare(strict_types=1);

namespace Bitrix\Crm\Activity\Email;

use CCrmOwnerType;

final class EntityType
{
	public const SUPPORTED_ENTITY_TYPE_IDS = [
		CCrmOwnerType::Lead,
		CCrmOwnerType::Deal,
		CCrmOwnerType::Contact,
		CCrmOwnerType::Company,
	];

	public static function isSupported(int $entityTypeId): bool
	{
		return in_array($entityTypeId, self::SUPPORTED_ENTITY_TYPE_IDS, true);
	}
}

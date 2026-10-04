<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\Models;

use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Structure\Entity;
use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Structure\Permission;

/**
 * Maps between the UI right id (`entityId~~~actionId`) and the internal {@see RightId}. The action id is
 * the numeric permission code from {@see \Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary},
 * so a decoded right id yields the permission id directly.
 */
final class RightIdConverter implements \Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Models\RightIdConverter
{
	private const SEPARATOR = '~~~';

	public function buildUIId(Entity $entity, Permission $permission): string
	{
		return $entity->getId() . self::SEPARATOR . $permission->getAction()->getId();
	}

	public function parseUIId(string $uiId): ?RightId
	{
		$parts = explode(self::SEPARATOR, $uiId);
		if (count($parts) < 2)
		{
			return null;
		}

		return new RightId($parts[0], $parts[1]);
	}

	public function parseRightModel(\Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Models\RightModel $model): ?RightId
	{
		if (!$model instanceof RightModel)
		{
			return null;
		}

		return new RightId($model->entityId, $model->actionId);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Access\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Note\Internal\Access\ActionDictionary;

class BaseRule extends AbstractRule
{
	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if ($this->isAbleToSkipChecking())
		{
			return true;
		}

		$params ??= [];
		if (!is_array($params))
		{
			$params = [];
		}

		$permissionMap = ActionDictionary::getActionPermissionMap();
		$permissionId = $permissionMap[$params['action']] ?? null;
		$params['permissionId'] = $permissionId;
		$params['item'] = $item;

		return $this->check($params);
	}

	public function check(array $params): bool
	{
		$permissionId = $params['permissionId'] ?? null;
		if ($permissionId === null)
		{
			return false;
		}

		return (bool)$this->user->getPermission((string)$permissionId);
	}

	protected function isAbleToSkipChecking(): bool
	{
		return $this->user->isAdmin();
	}
}

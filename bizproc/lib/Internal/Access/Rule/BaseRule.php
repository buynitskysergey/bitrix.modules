<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Access\Rule;

use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;

/**
 * Single flat-matrix rule: the action passed to the controller is the permission code from
 * {@see PermissionDictionary}. Toggler permissions ({@see PermissionDictionary::BIZPROC_TEMPLATE_CREATE}) are a plain
 * grant; multivariables permissions are checked against the template scope carried by the item.
 *
 * @property \Bitrix\Bizproc\Internal\Access\Model\UserModel $user
 * @property \Bitrix\Bizproc\Internal\Access\AccessController $controller
 */
class BaseRule extends AbstractRule
{
	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if ($this->controller->isAdmin())
		{
			return true;
		}

		$params = is_array($params) ? $params : [];
		$permissionId = (string)($params['action'] ?? '');
		if ($permissionId === '')
		{
			return false;
		}

		$descriptor = PermissionDictionary::getPermission($permissionId);
		if (($descriptor['type'] ?? null) === PermissionDictionary::TYPE_MULTIVARIABLES)
		{
			return $this->checkScope($permissionId, $item);
		}

		return (bool)$this->user->getPermission($permissionId);
	}

	private function checkScope(string $permissionId, ?AccessibleItem $item): bool
	{
		$scope = $this->user->getPermissionMulti($permissionId);
		if (empty($scope))
		{
			return false;
		}

		if (in_array(PermissionDictionary::VALUE_VARIATION_ALL, $scope, true))
		{
			return true;
		}

		if ($item === null)
		{
			return true;
		}

		return in_array($item->getId(), $scope, true);
	}
}

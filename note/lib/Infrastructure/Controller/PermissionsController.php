<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Main\Error;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Access\AccessController;
use Bitrix\Note\Internal\Access\ActionDictionary;
use Bitrix\Note\Internal\Access\Component\PermissionConfig;
use Bitrix\Note\Internal\Access\Service\RolePermissionService;
use Bitrix\Note\Internal\Service\License\LicenseService;

class PermissionsController extends Controller
{
	/**
	 * @return array{USER_GROUPS: array, ACCESS_RIGHTS: array}
	 */
	public function loadGlobalAction(): array
	{
		if (!$this->checkAccessPermissions())
		{
			return [];
		}

		return $this->loadData();
	}

	/**
	 * @param array[] $userGroups
	 * @param string[] $deletedUserGroups
	 * @param array $parameters
	 * @param array[] $accessRights
	 *
	 * @return null|array{USER_GROUPS: array, ACCESS_RIGHTS: array}
	 */
	public function saveGlobalAction(
		array $userGroups = [],
		array $deletedUserGroups = [],
		array $parameters = [],
		array $accessRights = [],
	): ?array
	{
		if (!$this->checkAccessPermissions())
		{
			return null;
		}

		$service = new RolePermissionService();

		if (!empty($deletedUserGroups))
		{
			foreach ($deletedUserGroups as $deletedRole)
			{
				$service->deleteRole((int)$deletedRole);
			}
		}

		$savePermissionsResult = $service->saveRolePermissions($userGroups);
		if (!$savePermissionsResult->isSuccess())
		{
			$this->addErrors($savePermissionsResult->getErrors());

			return null;
		}

		return $this->loadData();
	}

	private function loadData(): array
	{
		$configPermissions = new PermissionConfig();

		return [
			'USER_GROUPS' => $configPermissions->getUserGroups(),
			'ACCESS_RIGHTS' => $configPermissions->getAccessRights(),
		];
	}

	private function checkAccessPermissions(): bool
	{
		// Tariff/tool gate before ACL: same denial as an ACL failure.
		if ($this->createLicenseService()->isAccessBlocked())
		{
			$this->addError(new Error((string)(Loc::getMessage('NOTE_ACCESS_DENIED'))));

			return false;
		}

		if (AccessController::getCurrent()->check(ActionDictionary::ACTION_NOTE_EDIT_PERMISSIONS))
		{
			return true;
		}

		$this->addError(new Error((string)(Loc::getMessage('NOTE_ACCESS_DENIED'))));

		return false;
	}

	protected function createLicenseService(): LicenseService
	{
		return new LicenseService();
	}
}

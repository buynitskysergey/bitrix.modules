<?php

namespace Bitrix\Sign\Access\Install;

use Bitrix\Crm\Integration\Sign\Access\Service\RolePermissionService;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Sign\Access\Permission\PermissionTable;
use Bitrix\Sign\Access\Permission\SignPermissionDictionary;
use Bitrix\Sign\Access\Service\RolePermissionService as SignRolePermissionService;
use CCrmRole;
use Bitrix\Crm\Service\UserPermissions;

class AccessInstaller
{
	public static function installMissingSafeFolderPermissions(): void
	{
		if (!Loader::includeModule('crm'))
		{
			return;
		}

		self::installMissingPermissions(
			[
				SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_CREATE,
				SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_READ,
				SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_WRITE,
				SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_DELETE,
			],
			[
				SignRolePermissionService::DEFAULT_ROLE_EMPLOYEE_CODE => UserPermissions::PERMISSION_SELF,
				SignRolePermissionService::DEFAULT_ROLE_CHIEF_CODE => UserPermissions::PERMISSION_SUBDEPARTMENT,
			],
		);
	}

	public static function installMissingDocumentAnnulPermission(): void
	{
		if (!Loader::includeModule('crm'))
		{
			return;
		}

		self::installMissingPermissions(
			[SignPermissionDictionary::SIGN_DOCUMENT_ANNUL],
			[
				SignRolePermissionService::DEFAULT_ROLE_EMPLOYEE_CODE => UserPermissions::PERMISSION_NONE,
				SignRolePermissionService::DEFAULT_ROLE_CHIEF_CODE => UserPermissions::PERMISSION_SUBDEPARTMENT,
			],
		);
	}

	/**
	 * Adds to the default roles only the permission rows they are missing, so values already stored
	 * (including ones an administrator has changed) stay untouched and a repeated run changes nothing.
	 * Requires the crm module to be loaded.
	 *
	 * @param list<int> $permissionIds
	 * @param array<string, string> $defaultValueByRoleCode
	 */
	private static function installMissingPermissions(array $permissionIds, array $defaultValueByRoleCode): void
	{
		$roles = CCrmRole::GetList(
			['ID' => 'DESC'],
			['=GROUP_CODE' => RolePermissionService::ROLE_GROUP_CODE],
		);

		while ($role = $roles->Fetch())
		{
			$value = $defaultValueByRoleCode[$role['CODE']] ?? null;
			// PERMISSION_NONE is an empty value: RolePermissionService never stores such a row either,
			// and a missing row already reads as "no permission".
			if ($value === null || $value === UserPermissions::PERMISSION_NONE)
			{
				continue;
			}

			$roleId = (int)$role['ID'];
			$existingPermissionIds = PermissionTable::query()
				->setSelect(['PERMISSION_ID'])
				->where('ROLE_ID', $roleId)
				->whereIn('PERMISSION_ID', array_map('strval', $permissionIds))
				->fetchAll()
			;
			$existingPermissionIds = array_column($existingPermissionIds, 'PERMISSION_ID', 'PERMISSION_ID');

			foreach ($permissionIds as $permissionId)
			{
				if (!isset($existingPermissionIds[(string)$permissionId]))
				{
					PermissionTable::add([
						'ROLE_ID' => $roleId,
						'PERMISSION_ID' => (string)$permissionId,
						'VALUE' => $value,
					]);
				}
			}
		}
	}

	public static function install($removeAllPrevious = false): string
	{
		try
		{
			if (!Loader::includeModule('crm'))
			{
				return '';
			}
		}
		catch (LoaderException)
		{
			return '';
		}

		if ($removeAllPrevious)
		{
			PermissionTable::deleteList(['>ID' => 0]);
		}
		$roles = CCrmRole::GetList(
			['ID' => 'DESC'],
			['=GROUP_CODE' => RolePermissionService::ROLE_GROUP_CODE],
		);

		$rolesToInstall = [
			SignRolePermissionService::DEFAULT_ROLE_EMPLOYEE_CODE => [
				[
					'accessRights' => [
						[
							'id' => SignPermissionDictionary::SIGN_MY_SAFE_DOCUMENTS,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_TEMPLATES,
							'value' => UserPermissions::PERMISSION_ALL,
						],
						[
							'id' => SignPermissionDictionary::SIGN_MY_SAFE,
							'value' => 1,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE,
							'value' => 1,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_DOCUMENTS,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_CREATE,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_READ,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_WRITE,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_DELETE,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FIRED,
							'value' => UserPermissions::PERMISSION_NONE,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_TEMPLATE_WRITE,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_TEMPLATE_CREATE,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_TEMPLATE_READ,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_TEMPLATE_DELETE,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_ADD,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_EDIT,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_READ,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_DELETE,
							'value' => UserPermissions::PERMISSION_SELF,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_REFUSED,
							'value' => 1,
						],
						[
							'id' => SignPermissionDictionary::SIGN_DOCUMENT_ANNUL,
							'value' => UserPermissions::PERMISSION_NONE,
						],
					],
				],
			],
			SignRolePermissionService::DEFAULT_ROLE_CHIEF_CODE =>[
				[
					'accessRights' => [
						[
							'id' => SignPermissionDictionary::SIGN_MY_SAFE_DOCUMENTS,
							'value' => UserPermissions::PERMISSION_ALL,
						],
						[
							'id' => SignPermissionDictionary::SIGN_TEMPLATES,
							'value' => UserPermissions::PERMISSION_ALL,
						],
						[
							'id' => SignPermissionDictionary::SIGN_MY_SAFE,
							'value' => 1,
						],
						[
							'id' => SignPermissionDictionary::SIGN_ACCESS_RIGHTS,
							'value' => 1,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE,
							'value' => 1,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_DOCUMENTS,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_CREATE,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_READ,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_WRITE,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FOLDER_DELETE,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_MY_SAFE_FIRED,
							'value' => UserPermissions::PERMISSION_NONE,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_TEMPLATE_WRITE,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_TEMPLATE_CREATE,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_TEMPLATE_READ,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_TEMPLATE_DELETE,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_ADD,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_EDIT,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_READ,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_DELETE,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
						[
							'id' => SignPermissionDictionary::SIGN_B2E_SIGNERS_LIST_REFUSED,
							'value' => 1,
						],
						[
							'id' => SignPermissionDictionary::SIGN_DOCUMENT_ANNUL,
							'value' => UserPermissions::PERMISSION_SUBDEPARTMENT,
						],
					],
				],
			],
		];

		$installed = false;
		while ($role = $roles->Fetch())
		{
			foreach ($rolesToInstall as $roleToInstall => $permission)
			{
				if ($role['CODE'] === $roleToInstall)
				{
					$permission[0]['id'] = $role['ID'];
					(new SignRolePermissionService())->saveRolePermissions($permission);
					$installed = true;
				}
			}
		}

		if ($installed)
		{
			return '';
		}

		return '\Bitrix\Sign\Access\Install\AccessInstaller::install();';
	}
}

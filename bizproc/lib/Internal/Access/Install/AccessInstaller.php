<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Access\Install;

use Bitrix\Bizproc\Internal\Access\Role\RoleDictionary;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Model\PermissionTable;
use Bitrix\Bizproc\Internal\Repository\Access\AccessRepository;
use Bitrix\Bizproc\Internal\Repository\Access\AccessRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\Mapper\AccessPermissionMapper;
use Bitrix\Bizproc\Internal\Repository\Mapper\AccessRoleMapper;
use Bitrix\Main\Application;

/**
 * Seeds the preset bizproc access roles WITHOUT any user relations: only `b_bp_access_role` and
 * `b_bp_access_permission` are filled, `b_bp_access_role_relation` stays empty (access is not widened
 * automatically on release — the portal administrator keeps full access outside the matrix).
 *
 * Seeding is idempotent: a role whose name already exists is skipped, so a re-run on a dirty portal is a
 * no-op and never overwrites a matrix an administrator has already changed.
 */
final class AccessInstaller
{
	/**
	 * Entry point for both delivery paths (install/index.php::InstallDB and AccessSeedStepper on update):
	 * seeding under a lock + transaction, so concurrent passes cannot duplicate a role and a failed matrix
	 * insert is rolled back together with the role it belongs to.
	 */
	public static function install(): void
	{
		$connection = Application::getConnection();
		$lockName = PermissionTable::getTableName();

		if (!$connection->lock($lockName, 60))
		{
			return;
		}

		try
		{
			$connection->startTransaction();
			self::seedDefaultRoles();
			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	/**
	 * Transaction-free idempotent seeding of the preset roles and their permission matrix.
	 */
	public static function seedDefaultRoles(): void
	{
		$repository = self::getRepository();

		foreach (RoleMap::getDefaultMap() as $roleCode => $roleClass)
		{
			if (!is_subclass_of($roleClass, Role\Base::class))
			{
				continue;
			}

			$roleName = RoleDictionary::getRoleName($roleCode);
			if ($repository->roleExistsByName($roleName))
			{
				continue;
			}

			$addResult = $repository->addRole($roleName);
			if (!$addResult->isSuccess())
			{
				continue;
			}
			$roleId = (int)$addResult->getId();

			$rows = [];
			foreach ((new $roleClass())->getMap() as $item)
			{
				$rows[] = [
					'ROLE_ID' => $roleId,
					'PERMISSION_ID' => $item['permissionId'],
					'VALUE' => $item['value'],
				];
			}

			$repository->insertPermissions($rows);
		}
	}

	/**
	 * Install-time callers can run before the module services are registered — an updater hit sees the
	 * new classes, but still the old .settings.php.
	 */
	private static function getRepository(): AccessRepositoryInterface
	{
		return Container::getAccessRepository()
			?? new AccessRepository(new AccessRoleMapper(), new AccessPermissionMapper())
		;
	}
}

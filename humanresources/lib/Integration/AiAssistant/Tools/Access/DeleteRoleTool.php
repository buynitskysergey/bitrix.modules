<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Access;

use Bitrix\HumanResources\Enum\Access\RoleCategory;
use Bitrix\HumanResources\Access\Role\RoleDictionary;
use Bitrix\HumanResources\Service\Container;

/**
 * Deletes an access-rights role. The category is required to authorise the operation and to make sure
 * the role really belongs to it. Permission/relation caches are invalidated by the domain service.
 *
 * @see install/components/bitrix/humanresources.config.permissions/ajax.php — deleteUserGroups
 * @see \Bitrix\HumanResources\Service\Access\RolePermissionService::deleteRoles()
 */
class DeleteRoleTool extends AccessBaseTool
{
	public function canList(int $userId): bool
	{
		return $this->canManageAnyCategory($userId);
	}

	public function canRun(int $userId): bool
	{
		return $this->canManageAnyCategory($userId);
	}

	public function getName(): string
	{
		return 'hr_delete_role';
	}

	public function getDescription(): string
	{
		return 'Delete an access-rights role by id. Provide the role "category" (department or team) to '
			. 'authorise the deletion and confirm the role belongs to that category. This removes the role '
			. 'and its permissions; it is a destructive operation. WARNING: deleting a role also '
			. 'removes all its assignments to users, groups and other access codes.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'category' => [
					'type' => 'string',
					'enum' => ['department', 'team'],
					'description' => 'Role category: "department" or "team".',
				],
				'roleId' => [
					'type' => 'integer',
					'minimum' => 1,
					'description' => 'Id of the role to delete. Deletion also removes all role assignments '
						. 'to users, groups and other access codes.',
				],
			],
			'additionalProperties' => false,
			'required' => ['category', 'roleId'],
		];
	}

	public function execute(int $userId, ...$args): string
	{
		// category enum/required are enforced by the tool input validator (ToolManager::callTool).
		$category = RoleCategory::from(strtoupper((string)$args['category']));

		$roleId = isset($args['roleId']) ? (int)$args['roleId'] : 0;
		if ($roleId <= 0)
		{
			return 'roleId is required.';
		}

		if (!$this->requireManageCategory($userId, $category))
		{
			return "Access denied: you cannot manage access rights for the '" . strtolower($category->value) . "' category.";
		}

		try
		{
			$service = Container::getAccessRolePermissionService();
			$service->setCategory($category);

			$role = $this->findRole($service->getRoleList(), $roleId);
			if ($role === null)
			{
				return "Role #{$roleId} not found in the '" . strtolower($category->value) . "' category.";
			}

			if (isset(RoleDictionary::getConstants()[(string)$role['NAME']]))
			{
				return "Role #{$roleId} is predefined and cannot be deleted.";
			}

			$relationCount = Container::getAccessRoleRelationRepository()
				->getRoleRelationsCountByRoleId($roleId)
			;
			$service->deleteRoles([$roleId]);

			return "Role #{$roleId} deleted (category: " . strtolower($category->value)
				. "); removed role assignments: {$relationCount}.";
		}
		catch (\Exception $e)
		{
			$this->logException('Error deleting role: ' . $e->getMessage());

			return 'Error deleting role.';
		}
	}

	/**
	 * @param array<array{ID: int|string, NAME?: string, CATEGORY?: string}> $roles
	 */
	private function findRole(array $roles, int $roleId): ?array
	{
		foreach ($roles as $role)
		{
			if ((int)$role['ID'] === $roleId)
			{
				return $role;
			}
		}

		return null;
	}
}

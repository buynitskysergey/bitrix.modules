<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Access;

use Bitrix\HumanResources\Access\Role\RoleDictionary;
use Bitrix\HumanResources\Enum\Access\RoleCategory;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Schema\InputProperty;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main\Text\Encoding;

/**
 * Updates the permission areas of an existing role. The provided access rights replace the role's
 * current permissions (pass the complete target set). The role is not assigned to anyone
 * (saveRoleRelation is NOT called).
 *
 * @see install/components/bitrix/humanresources.config.permissions/ajax.php::savePermissionsAction
 */
class UpdateRoleTool extends AccessBaseTool
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
		return 'hr_update_role';
	}

	public function getDescription(): string
	{
		return 'Update the permission areas of an existing access-rights role. The provided access rights '
			. 'REPLACE the role current permissions, so pass the complete target set. The list must not be '
			. 'empty: to drop every permission pass an explicit area 0 for each one, and to remove the role '
			. 'use hr_delete_role. Each access right is {permissionId, area} (0=None, 10=own departments, '
			. '20=own and sub-departments, 30=all; for team permissions also 9=own teams, 19=own and sub-teams). '
			. 'A permission can only be scoped by area, never bound to a node id. Optionally rename the role with "title".';
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
					'description' => 'Id of the role to update.',
				],
				'title' => [
					'type' => 'string',
					'minLength' => 1,
					'description' => 'Optional new name for the role. If omitted, the current name is kept.',
				],
				'accessRights' => InputProperty::accessRights(),
			],
			'additionalProperties' => false,
			'required' => ['category', 'roleId', 'accessRights'],
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

		$accessRights = $args['accessRights'] ?? [];
		$built = $this->buildAndValidateAccessRights($category, is_array($accessRights) ? $accessRights : []);
		if ($built['error'] !== null)
		{
			return $built['error'];
		}

		// A literal empty accessRights is rejected: "clear everything" is not an implicit gesture.
		if (empty($built['rights']))
		{
			return 'accessRights must not be empty. To remove every permission from the role pass an '
				. 'explicit area 0 for each permission; to delete the role entirely use hr_delete_role.';
		}

		try
		{
			$service = Container::getAccessRolePermissionService();
			$service->setCategory($category);

			$existingName = $this->findRoleName($service->getRoleList(), $roleId);
			if ($existingName === null)
			{
				return "Role #{$roleId} not found in the '" . strtolower($category->value) . "' category.";
			}

			// Keep the stored (raw) name when no new title is given, so a system role is not renamed
			// to its display text.
			$title = isset($args['title']) ? trim((string)$args['title']) : '';
			if ($title === '')
			{
				$title = $existingName;
			}
			$normalizedTitle = Encoding::convertEncodingToCurrent($title);

			if (
				isset(RoleDictionary::getConstants()[$existingName])
				&& $normalizedTitle !== $existingName
				&& $normalizedTitle !== RoleDictionary::getRoleName($existingName)
			)
			{
				return "Role #{$roleId} is predefined and cannot be renamed.";
			}

			$userGroups = [[
				'id' => $roleId,
				'title' => $title,
				'accessRights' => $built['rights'],
			]];

			$service->saveRolePermissions($userGroups);

			return "Role #{$roleId} updated (category: " . strtolower($category->value) . ').';
		}
		catch (\DomainException $e)
		{
			return "Role #{$roleId} cannot be updated: " . $e->getMessage();
		}
		catch (\Exception $e)
		{
			$this->logException('Error updating role: ' . $e->getMessage());

			return 'Error updating role.';
		}
	}

	/**
	 * @param array<array{ID: int|string, NAME: string, CATEGORY?: string}> $roles
	 */
	private function findRoleName(array $roles, int $roleId): ?string
	{
		foreach ($roles as $role)
		{
			if ((int)$role['ID'] === $roleId)
			{
				return (string)$role['NAME'];
			}
		}

		return null;
	}
}

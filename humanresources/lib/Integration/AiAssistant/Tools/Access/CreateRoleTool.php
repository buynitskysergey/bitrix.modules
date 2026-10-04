<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Access;

use Bitrix\HumanResources\Enum\Access\RoleCategory;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Schema\InputProperty;
use Bitrix\HumanResources\Service\Container;

/**
 * Creates an access-rights role in a category (department or team) and sets its permission areas.
 *
 * Mirrors the permissions UI write path (savePermissionsAction -> saveRolePermissions) but, by
 * design, never assigns the role to users/groups: saveRoleRelation is NOT called (the tool only
 * manages role permission values, not role relations).
 *
 * @see install/components/bitrix/humanresources.config.permissions/ajax.php::savePermissionsAction
 */
class CreateRoleTool extends AccessBaseTool
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
		return 'hr_create_role';
	}

	public function getDescription(): string
	{
		return 'Create an access-rights role for a category (department or team) and set its permission '
			. 'areas. Each access right is {permissionId, area}, where area is a scope value: '
			. '0=None, 10=own departments, 20=own and sub-departments, 30=all (for team permissions also '
			. '9=own teams, 19=own and sub-teams). A permission can only be scoped by area; it CANNOT be '
			. 'bound to a specific department or team by id. The role is not assigned to anyone.';
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
				'title' => [
					'type' => 'string',
					'minLength' => 1,
					'description' => 'Role name.',
				],
				'accessRights' => InputProperty::accessRights(),
			],
			'additionalProperties' => false,
			'required' => ['category', 'title', 'accessRights'],
		];
	}

	public function execute(int $userId, ...$args): string
	{
		// category enum/required are enforced by the tool input validator (ToolManager::callTool).
		$category = RoleCategory::from(strtoupper((string)$args['category']));

		$title = trim((string)($args['title'] ?? ''));
		if ($title === '')
		{
			return 'title is required.';
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

		try
		{
			$userGroups = [[
				'id' => '',
				'title' => $title,
				'accessRights' => $built['rights'],
			]];

			Container::getAccessRolePermissionService()
				->setCategory($category)
				->saveRolePermissions($userGroups)
			;

			$newRoleId = (int)($userGroups[0]['id'] ?? 0);

			return "Role '{$title}' created (id: {$newRoleId}, category: " . strtolower($category->value) . ').';
		}
		catch (\DomainException $e)
		{
			return 'Role cannot be created: ' . $e->getMessage();
		}
		catch (\Exception $e)
		{
			$this->logException('Error creating role: ' . $e->getMessage());

			return 'Error creating role.';
		}
	}
}

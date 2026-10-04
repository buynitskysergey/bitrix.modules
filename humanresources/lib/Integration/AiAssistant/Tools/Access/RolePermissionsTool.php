<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Access;

use Bitrix\HumanResources\Access\Enum\PermissionValueType;
use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Access\Permission\PermissionVariablesDictionary;
use Bitrix\HumanResources\Access\Role\RoleDictionary;
use Bitrix\HumanResources\Enum\Access\RoleCategory;
use Bitrix\HumanResources\Service\Container;

/**
 * Returns the permissions of a role, with each permission area explained in words.
 *
 * Team permissions are reported on both axes (teams and departments), because a team
 * permission is stored as a (team + department) value pair.
 *
 * @see \Bitrix\HumanResources\Service\Access\RolePermissionService::getRoleAccessRights()
 * @see \Bitrix\HumanResources\Service\Access\RolePermissionService::getAccessRights()
 */
class RolePermissionsTool extends AccessBaseTool
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
		return 'hr_role_permissions';
	}

	public function getDescription(): string
	{
		return 'Return the permissions of an access-rights role. '
			. 'Identify the role by "roleId" or by "roleName". '
			. 'Each permission is reported with its area explained in words (None / own departments / '
			. 'own and sub-departments / all). Permissions describe a rule relative to the role holder, '
			. 'not a fixed list of nodes. Team permissions are reported on both axes (teams and departments).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'roleId' => [
					'type' => 'integer',
					'minimum' => 1,
					'description' => 'Role id (preferred). Obtain it from hr_role_list.',
				],
				'roleName' => [
					'type' => 'string',
					'minLength' => 1,
					'description' => 'Role name, used when the id is unknown (case-insensitive match).',
				],
				'category' => [
					'type' => 'string',
					'enum' => ['department', 'team', 'both'],
					'description' => 'Limit the search to a role category. Defaults to "both".',
					'default' => 'both',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$roleId = isset($args['roleId']) ? (int)$args['roleId'] : 0;
		$roleName = isset($args['roleName']) ? trim((string)$args['roleName']) : '';

		if ($roleId <= 0 && $roleName === '')
		{
			return 'Provide either roleId or roleName.';
		}

		try
		{
			$categories = $this->resolveCategories($args['category'] ?? 'both');
			if (!$this->canManageCategories($userId, $categories))
			{
				return 'Access denied: permission to manage every requested role category is required.';
			}

			$service = Container::getAccessRolePermissionService();
			$blocks = [];

			foreach ($categories as $category)
			{
				$service->setCategory($category);

				// Find the target role(s) via the lightweight getRoleList() (ids + names), then read
				// permissions only for matches — avoids computing accessRights/members of every role.
				$titleMaps = null;
				foreach ($service->getRoleList() as $row)
				{
					$id = (int)$row['ID'];
					$title = RoleDictionary::getRoleName((string)$row['NAME']);
					if (!$this->matchesRole($id, $title, $roleId, $roleName))
					{
						continue;
					}

					$titleMaps ??= $this->buildCategoryTitleMaps($service->getAccessRights());
					$role = [
						'id' => $id,
						'title' => $title,
						'accessRights' => $service->getRoleAccessRights($id),
					];

					$blocks[] = $this->formatRole($role, $category, $titleMaps);
				}
			}

			if (empty($blocks))
			{
				return 'No role found for the given identifier.';
			}

			return implode("\n\n", $blocks);
		}
		catch (\Exception $e)
		{
			$this->logException('Error reading role permissions: ' . $e->getMessage());

			return 'Error reading role permissions.';
		}
	}

	private function matchesRole(int $id, string $title, int $roleId, string $roleName): bool
	{
		if ($roleId > 0)
		{
			return $id === $roleId;
		}

		return mb_strtolower($title) === mb_strtolower($roleName);
	}

	/**
	 * @return array{permissions: array<string, string>, variables: array<string, array<int, string>>}
	 */
	private function buildCategoryTitleMaps(array $accessRightsCatalog): array
	{
		$permissionTitles = [];
		$variableTitles = [];

		foreach ($accessRightsCatalog as $section)
		{
			foreach ($section['rights'] ?? [] as $right)
			{
				$permissionId = (string)$right['id'];
				$permissionTitles[$permissionId] = (string)($right['title'] ?? $permissionId);

				$map = [];
				foreach ($right['variables'] ?? [] as $variable)
				{
					$map[(int)$variable['id']] = (string)($variable['title'] ?? '');
				}
				$variableTitles[$permissionId] = $map;
			}
		}

		return ['permissions' => $permissionTitles, 'variables' => $variableTitles];
	}

	private function formatRole(array $role, RoleCategory $category, array $titleMaps): string
	{
		$categoryLabel = $category === RoleCategory::Department ? 'departments' : 'teams';

		// getUserGroups() already expands team permissions into per-axis checkbox values, so a team
		// permission appears several times under its base id; group by id to recover the axis pair.
		$valuesByPermission = array_fill_keys(
			array_keys($titleMaps['permissions']),
			[],
		);
		foreach ($role['accessRights'] ?? [] as $right)
		{
			$valuesByPermission[(string)$right['id']][] = (int)$right['value'];
		}

		$lines = [];
		foreach ($valuesByPermission as $permissionId => $values)
		{
			// numeric-string array keys are auto-cast to int by PHP; restore the string id.
			$permissionId = (string)$permissionId;
			$permissionTitle = $titleMaps['permissions'][$permissionId] ?? $this->permissionTitle($permissionId);
			$variableTitle = $titleMaps['variables'][$permissionId] ?? $this->buildVariableTitleMap($permissionId);

			// Toggler permissions (e.g. manage access rights, fire employee) are yes/no, not areas.
			if (PermissionDictionary::getType($permissionId) === PermissionDictionary::TYPE_TOGGLER)
			{
				$area = (int)($values[0] ?? 0) > 0
					? PermissionDictionary::VALUE_YES
					: PermissionDictionary::VALUE_NO;
				$lines[] = $this->formatTogglerPermission(
					$permissionId,
					$permissionTitle,
					$area,
				);

				continue;
			}

			if (PermissionDictionary::isTeamDependentVariablesPermission($permissionId))
			{
				[$teamAxis, $departmentAxis] = $this->reduceTeamAxes($values);
				$lines[] = $this->formatTeamPermissionArea(
					$permissionId,
					$permissionTitle,
					$teamAxis,
					$this->areaTitle($variableTitle, $teamAxis),
					$departmentAxis,
					$this->areaTitle($variableTitle, $departmentAxis),
				);

				continue;
			}

			$area = (int)($values[0] ?? 0);
			$lines[] = $this->formatPermissionArea(
				$permissionId,
				$permissionTitle,
				$area,
				$this->areaTitle($variableTitle, $area),
			);
		}

		$header = "Role \"{$role['title']}\" (id: {$role['id']}, {$categoryLabel})";

		return $header . ":\n" . (empty($lines) ? 'no permissions granted' : implode("\n", $lines));
	}

	/**
	 * Reduces the expanded checkbox values of a team permission back to the (team, department) pair.
	 *
	 * @param list<int> $values
	 * @return array{0: int, 1: int}
	 */
	private function reduceTeamAxes(array $values): array
	{
		$teamAxis = PermissionVariablesDictionary::VARIABLE_NONE;
		$departmentAxis = PermissionVariablesDictionary::VARIABLE_NONE;

		foreach ($values as $value)
		{
			switch (PermissionVariablesDictionary::getPermissionValueType($value))
			{
				case PermissionValueType::AllValue:
					$teamAxis = max($teamAxis, PermissionVariablesDictionary::VARIABLE_ALL);
					$departmentAxis = max($departmentAxis, PermissionVariablesDictionary::VARIABLE_ALL);

					break;
				case PermissionValueType::TeamValue:
					$teamAxis = max($teamAxis, $value);

					break;
				case PermissionValueType::DepartmentValue:
					$departmentAxis = max($departmentAxis, $value);

					break;
				case PermissionValueType::NoneValue:
					break;
			}
		}

		return [$teamAxis, $departmentAxis];
	}
}

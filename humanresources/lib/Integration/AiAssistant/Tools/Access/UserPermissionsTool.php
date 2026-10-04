<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Access;

use Bitrix\HumanResources\Access\Permission\Mapper\TeamPermissionMapper;
use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Access\Permission\PermissionHelper;
use Bitrix\HumanResources\Access\Permission\PermissionVariablesDictionary;
use Bitrix\HumanResources\Enum\Access\RoleCategory;

/**
 * Returns the effective access permissions of a user (permission -> area, explained in words).
 *
 * Areas are rules relative to the user (None / own departments / own and sub-departments / all),
 * not a list of concrete nodes.
 *
 * Effective values are read through PermissionHelper::getPermissionValue(), which already accounts
 * for admins (ALL), togglers and the team value pair, and aggregates across the user's roles by max.
 *
 * @see lib/Controller/Structure/Access.php — controller reference
 */
class UserPermissionsTool extends AccessBaseTool
{
	public function canList(int $userId): bool
	{
		return $this->canReadRoles($userId) || $this->canManageAnyCategory($userId);
	}

	public function canRun(int $userId): bool
	{
		return $this->canReadRoles($userId) || $this->canManageAnyCategory($userId);
	}

	public function getName(): string
	{
		return 'hr_user_permissions';
	}

	public function getDescription(): string
	{
		return 'Return the effective organisation access permissions of a user (permission -> area, '
			. 'explained in words). Areas are rules relative to the user (None / own departments / '
			. 'own and sub-departments / all), NOT a list of concrete departments or teams. '
			. 'Team permissions are reported on both axes (teams and departments). '
			. 'Toggler permissions (e.g. manage access rights, fire employee) are reported as yes/no. '
			. 'Reading another user requires permission to manage access rights.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'employeeId' => [
					'type' => 'integer',
					'minimum' => 1,
					'description' => 'Id of the employee (user) whose permissions to report.',
				],
			],
			'additionalProperties' => false,
			'required' => ['employeeId'],
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$targetUserId = isset($args['employeeId']) ? (int)$args['employeeId'] : 0;
		if ($targetUserId <= 0)
		{
			return 'employeeId is required.';
		}

		$isSelf = $targetUserId === $userId;

		// STRUCTURE_VIEW grants self-read for both categories. Otherwise each category is disclosed only
		// if the caller manages it, so managing department rights never reveals team permissions.
		$canReadSelf = $isSelf && $this->canReadRoles($userId);
		$showDepartment = $canReadSelf || $this->requireManageCategory($userId, RoleCategory::Department);
		$showTeam = $canReadSelf || $this->requireManageCategory($userId, RoleCategory::Team);

		if (!$showDepartment && !$showTeam)
		{
			return 'Access denied: reading user permissions requires structure view for self-read '
				. 'or permission to manage an access-rights category.';
		}

		try
		{
			$blocks = [];

			if ($showDepartment)
			{
				$departmentLines = $this->describePermissions(
					$this->relevantPermissionIds(PermissionDictionary::getDepartmentCategoryPermissionIds(), false),
					$targetUserId,
				);
				$blocks[] = "Departments:\n" . (empty($departmentLines) ? 'none' : implode("\n", $departmentLines));
			}

			if ($showTeam)
			{
				$teamLines = $this->describePermissions(
					$this->relevantPermissionIds(PermissionDictionary::getTeamCategoryPermissionIds(), true),
					$targetUserId,
				);
				$blocks[] = "Teams:\n" . (empty($teamLines) ? 'none' : implode("\n", $teamLines));
			}

			return "Effective access permissions for user #{$targetUserId}.\n\n"
				. implode("\n\n", $blocks)
				. "\n\nNote: areas describe rules relative to the user, not specific departments or teams. "
				. 'Toggler permissions are shown as yes/no.';
		}
		catch (\Exception $e)
		{
			$this->logException('Error reading user permissions: ' . $e->getMessage());

			return 'Error reading user permissions.';
		}
	}

	/**
	 * Keeps area-bearing permissions (variables / team-dependent variables) and toggler permissions
	 * of a category. Area permissions feed into PermissionHelper::getPermissionValue(); toggler
	 * permissions are also safe because PermissionHelper handles TYPE_TOGGLER explicitly before
	 * reaching the structure-action lookup.
	 *
	 * @param list<string> $categoryPermissionIds
	 * @return list<string>
	 */
	private function relevantPermissionIds(array $categoryPermissionIds, bool $teamDependent): array
	{
		return array_values(array_filter(
			$categoryPermissionIds,
			static fn(string $permissionId) => ($teamDependent
				? PermissionDictionary::isTeamDependentVariablesPermission($permissionId)
				: PermissionDictionary::isDepartmentVariablesPermission($permissionId))
				|| PermissionDictionary::getType($permissionId) === PermissionDictionary::TYPE_TOGGLER,
		));
	}

	/**
	 * @param list<string> $permissionIds
	 * @return list<string>
	 */
	private function describePermissions(array $permissionIds, int $targetUserId): array
	{
		$lines = [];

		foreach ($permissionIds as $permissionId)
		{
			$collection = PermissionHelper::getPermissionValue($permissionId, $targetUserId);
			$permissionTitle = $this->permissionTitle($permissionId);

			// Toggler permissions (e.g. manage access rights, fire employee) are yes/no, not areas.
			if (PermissionDictionary::getType($permissionId) === PermissionDictionary::TYPE_TOGGLER)
			{
				$area = ($collection->getFirst()?->value ?? 0) > 0
					? PermissionDictionary::VALUE_YES
					: PermissionDictionary::VALUE_NO;
				$lines[] = $this->formatTogglerPermission(
					$permissionId,
					$permissionTitle,
					$area,
				);

				continue;
			}

			$variableTitle = $this->buildVariableTitleMap($permissionId);

			if (PermissionDictionary::isTeamDependentVariablesPermission($permissionId))
			{
				$mapper = TeamPermissionMapper::createFromCollection($collection);
				$teamArea = $mapper->getTeamPermissionValue();
				$departmentArea = $mapper->getDepartmentPermissionValue();
				$lines[] = $this->formatTeamPermissionArea(
					$permissionId,
					$permissionTitle,
					$teamArea,
					$this->areaTitle($variableTitle, $teamArea),
					$departmentArea,
					$this->areaTitle($variableTitle, $departmentArea),
				);

				continue;
			}

			$area = $collection->getFirst()?->value ?? PermissionVariablesDictionary::VARIABLE_NONE;
			$lines[] = $this->formatPermissionArea(
				$permissionId,
				$permissionTitle,
				$area,
				$this->areaTitle($variableTitle, $area),
			);
		}

		return $lines;
	}
}

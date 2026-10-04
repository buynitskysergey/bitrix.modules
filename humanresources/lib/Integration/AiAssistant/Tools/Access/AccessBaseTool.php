<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Access;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\HumanResources\Access\Model\UserModel;
use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Access\Permission\PermissionHelper;
use Bitrix\HumanResources\Access\Permission\PermissionVariablesDictionary;
use Bitrix\HumanResources\Access\Permission\RolePermissionValidator;
use Bitrix\HumanResources\Enum\Access\RoleCategory;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Service\Container;

/**
 * Role/permission-scoped base tool for the "access" MCP surface.
 *
 * Unlike NodeBaseTool (node-scoped: STRUCTURE_VIEW + per-node StructureAccessController),
 * this base reuses the same auth gates as the permissions UI
 * (humanresources.config.permissions): reading the role configuration and managing roles both
 * require the category-aware AccessService::checkAccessToEditPermissions ("manage access rights").
 * The null-safe STRUCTURE_VIEW check (canReadRoles) is used only for a user reading their own
 * effective permissions.
 *
 * The real user id always comes from the execute() argument: CurrentUser::getId() is 0
 * in the MCP context.
 */
abstract class AccessBaseTool extends ToolContract
{
	/**
	 * Null-safe STRUCTURE_VIEW read check: true for admins and for users whose effective
	 * structure-view value is not NONE. The value is resolved through PermissionHelper so a missing
	 * permission yields NONE (and denial) instead of null.
	 *
	 * Used only for the self branch of hr_user_permissions (a user reading their own permissions).
	 * Reading the role configuration (hr_role_list / hr_role_permissions) is gated on the
	 * "manage access rights" permission via canManageAnyCategory(), mirroring the permissions UI.
	 */
	protected function canReadRoles(int $userId): bool
	{
		$user = UserModel::createFromId($userId);
		if ($user->isAdmin())
		{
			return true;
		}

		$value = PermissionHelper::getPermissionValue(
			PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW,
			$userId,
		)->getFirst()?->value ?? PermissionVariablesDictionary::VARIABLE_NONE;

		return $value !== PermissionVariablesDictionary::VARIABLE_NONE;
	}

	/**
	 * Coarse write gate for canList()/canRun(): the user manages access to at least one
	 * role category (Department OR Team). The precise per-category check happens inside
	 * execute() via requireManageCategory() once the target category is known.
	 */
	protected function canManageAnyCategory(int $userId): bool
	{
		return $this->requireManageCategory($userId, RoleCategory::Department)
			|| $this->requireManageCategory($userId, RoleCategory::Team);
	}

	/**
	 * @param list<RoleCategory> $categories
	 */
	protected function canManageCategories(int $userId, array $categories): bool
	{
		foreach ($categories as $category)
		{
			if (!$this->requireManageCategory($userId, $category))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Precise per-category write gate (category-aware, identical to the permissions UI):
	 * department -> USERS_ACCESS_EDIT (101), team -> TEAM_ACCESS_EDIT (308), plus the tariff
	 * gate and admin handling inside StructureAccessController. The user id is always passed
	 * explicitly because there is no session in the MCP context.
	 */
	protected function requireManageCategory(int $userId, RoleCategory $category): bool
	{
		return InternalContainer::getAccessService()
			->checkAccessToEditPermissions($category, $userId)
		;
	}

	protected function logException(string $message): void
	{
		Container::getStructureLogger()->write([
			'feature' => 'Integration\AiAssistant',
			'type' => 'error',
			'message' => $message,
		]);
	}

	/**
	 * Resolves the "category" tool argument (department|team|both) to a list of role categories.
	 *
	 * @return list<RoleCategory>
	 */
	protected function resolveCategories(mixed $categoryArg): array
	{
		return match (strtolower((string)($categoryArg ?? 'both')))
		{
			'department' => [RoleCategory::Department],
			'team' => [RoleCategory::Team],
			default => [RoleCategory::Department, RoleCategory::Team],
		};
	}

	/**
	 * Human-readable permission title (localized, reused from the permissions dictionary).
	 */
	protected function permissionTitle(string $permissionId): string
	{
		$title = PermissionDictionary::getTitle($permissionId);

		return $title !== '' ? $title : $permissionId;
	}

	/**
	 * Map of area value => localized title for a permission, taken straight from the dictionary
	 * (department or team variables, chosen automatically). Titles are never invented.
	 *
	 * @return array<int, string>
	 */
	protected function buildVariableTitleMap(string $permissionId): array
	{
		$map = [];
		foreach (PermissionDictionary::getVariables((int)$permissionId) as $variable)
		{
			$map[(int)$variable['id']] = (string)($variable['title'] ?? '');
		}

		return $map;
	}

	protected function formatPermissionArea(
		string $permissionId,
		string $permissionTitle,
		int $area,
		string $areaTitle,
	): string
	{
		return sprintf(
			'- permissionId: %s; permissionTitle: %s; area: %d; areaTitle: %s',
			$permissionId,
			$permissionTitle,
			$area,
			$areaTitle,
		);
	}

	protected function formatTogglerPermission(
		string $permissionId,
		string $permissionTitle,
		int $area,
	): string
	{
		return sprintf(
			'- permissionId: %s; permissionTitle: %s; permissionType: toggler; '
				. 'scope: none; area: %d; areaTitle: %s; allowedValues: 0=no,1=yes',
			$permissionId,
			$permissionTitle,
			$area,
			$area === PermissionDictionary::VALUE_YES ? 'yes' : 'no',
		);
	}

	protected function formatTeamPermissionArea(
		string $permissionId,
		string $permissionTitle,
		int $teamArea,
		string $teamAreaTitle,
		int $departmentArea,
		string $departmentAreaTitle,
	): string
	{
		return sprintf(
			'- permissionId: %s; permissionTitle: %s; '
				. 'teamArea: %d; teamAreaTitle: %s; departmentArea: %d; departmentAreaTitle: %s',
			$permissionId,
			$permissionTitle,
			$teamArea,
			$teamAreaTitle,
			$departmentArea,
			$departmentAreaTitle,
		);
	}

	/**
	 * @param array<int, string> $variableTitle
	 */
	protected function areaTitle(array $variableTitle, int $value): string
	{
		$title = $variableTitle[$value] ?? '';

		return $title !== '' ? $title : (string)$value;
	}

	/**
	 * Builds and validates the access-rights payload for write tools (create/update).
	 *
	 * Mirrors the permissions UI contract: every permission must belong to the role category,
	 * and every value must be an allowed area (NONE/SELF/SELF+SUB/ALL) — binding a permission to a
	 * specific node by id is rejected. For team permissions the (team + department) axis
	 * combination is additionally validated (see the inline note on requires/conflictsWith).
	 *
	 * @param list<array{permissionId?: mixed, area?: mixed}> $accessRights
	 * @return array{error: ?string, rights: list<array{id: string, value: int}>}
	 */
	protected function buildAndValidateAccessRights(RoleCategory $category, array $accessRights): array
	{
		$rights = [];
		foreach ($accessRights as $entry)
		{
			if (!is_array($entry))
			{
				return ['error' => 'Each access right must be an object {permissionId, area}.', 'rights' => []];
			}

			$permissionId = isset($entry['permissionId']) ? (string)$entry['permissionId'] : '';
			if (!array_key_exists('area', $entry))
			{
				return [
					'error' => "Access right for permission '{$permissionId}' is missing the 'area' value.",
					'rights' => [],
				];
			}

			$rights[] = [
				'id' => $permissionId,
				'value' => $entry['area'],
			];
		}

		try
		{
			$rights = (new RolePermissionValidator())->validate($category, $rights);
		}
		catch (\DomainException $exception)
		{
			return [
				'error' => $exception->getMessage(),
				'rights' => [],
			];
		}

		return ['error' => null, 'rights' => $rights];
	}
}

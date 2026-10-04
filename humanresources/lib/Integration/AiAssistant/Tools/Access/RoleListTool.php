<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Access;

use Bitrix\HumanResources\Access\Role\RoleDictionary;
use Bitrix\HumanResources\Enum\Access\RoleCategory;
use Bitrix\HumanResources\Service\Container;

/**
 * Lists access-rights roles, separated by category (departments / teams).
 *
 * Uses the lightweight getRoleList() (ids + names) instead of getUserGroups(): only names are
 * needed here, so per-role permission reads and member resolution are intentionally avoided.
 *
 * @see \Bitrix\HumanResources\Service\Access\RolePermissionService::getRoleList() — domain source
 * @see install/components/bitrix/humanresources.config.permissions/ajax.php::loadAction — UI analog
 */
class RoleListTool extends AccessBaseTool
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
		return 'hr_role_list';
	}

	public function getDescription(): string
	{
		return 'List the organisation access-rights roles, separated by category. '
			. 'Departments and teams are independent role categories, so roles are reported per category. '
			. 'Each role is returned with its id and name. '
			. 'Use the optional "category" argument to limit the output to departments or teams only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'category' => [
					'type' => 'string',
					'enum' => ['department', 'team', 'both'],
					'description' => 'Limit the listing to a role category. Defaults to "both".',
					'default' => 'both',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function execute(int $userId, ...$args): string
	{
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

				$lines = [];
				foreach ($service->getRoleList() as $row)
				{
					$id = (int)$row['ID'];
					$name = RoleDictionary::getRoleName((string)$row['NAME']);
					$lines[] = "- id: {$id}, name: {$name}";
				}

				$header = $category === RoleCategory::Department ? 'Departments' : 'Teams';
				$blocks[] = $header . ":\n" . (empty($lines) ? 'none' : implode("\n", $lines));
			}

			return "Access-rights roles by category.\n\n" . implode("\n\n", $blocks);
		}
		catch (\Exception $e)
		{
			$this->logException('Error listing roles: ' . $e->getMessage());

			return 'Error listing roles.';
		}
	}
}

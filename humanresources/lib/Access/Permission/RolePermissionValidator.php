<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Access\Permission;

use Bitrix\HumanResources\Enum\Access\RoleCategory;

final class RolePermissionValidator
{
	/**
	 * @param list<array{id?: mixed, value?: mixed}> $rights
	 * @return list<array{id: string, value: int}>
	 */
	public function validate(RoleCategory $category, array $rights): array
	{
		$allowedPermissionIds = $category === RoleCategory::Department
			? PermissionDictionary::getDepartmentCategoryPermissionIds()
			: PermissionDictionary::getTeamCategoryPermissionIds();
		$allowedPermissionMap = array_flip($allowedPermissionIds);
		$normalizedRights = [];
		$valuesByPermission = [];

		foreach ($rights as $right)
		{
			$permissionId = is_array($right) && isset($right['id'])
				? (string)$right['id']
				: '';
			if ($permissionId === '' || !isset($allowedPermissionMap[$permissionId]))
			{
				throw new \DomainException(
					"Permission '{$permissionId}' does not belong to the '"
						. strtolower($category->value)
						. "' category.",
				);
			}

			$value = is_array($right) && array_key_exists('value', $right)
				? $right['value']
				: null;
			if (!is_int($value))
			{
				throw new \DomainException("Value for permission '{$permissionId}' must be an integer.");
			}

			if (PermissionDictionary::getType($permissionId) === PermissionDictionary::TYPE_TOGGLER)
			{
				$this->validateToggler($permissionId, $value);
			}
			else
			{
				$this->validateArea($permissionId, $value);
			}

			if (
				!PermissionDictionary::isTeamDependentVariablesPermission($permissionId)
				&& isset($valuesByPermission[$permissionId])
			)
			{
				throw new \DomainException("Permission '{$permissionId}' must be specified only once.");
			}

			$normalizedRights[] = [
				'id' => $permissionId,
				'value' => $value,
			];
			$valuesByPermission[$permissionId][] = $value;
		}

		if ($category === RoleCategory::Team)
		{
			$this->validateTeamPermissionCombinations($valuesByPermission);
		}

		return $normalizedRights;
	}

	private function validateToggler(string $permissionId, int $value): void
	{
		if (in_array($value, [PermissionDictionary::VALUE_NO, PermissionDictionary::VALUE_YES], true))
		{
			return;
		}

		throw new \DomainException(
			"Value '{$value}' is not allowed for permission '{$permissionId}'. "
				. 'This is a toggler permission; allowed values are 0 (no) and 1 (yes).',
		);
	}

	private function validateArea(string $permissionId, int $value): void
	{
		$allowedAreas = array_column(PermissionDictionary::getVariables((int)$permissionId), 'id');
		if (in_array($value, $allowedAreas, true))
		{
			return;
		}

		throw new \DomainException(
			"Area '{$value}' is not allowed for permission '{$permissionId}'. "
				. 'Permissions can only be scoped by area (NONE/SELF/SELF+SUB/ALL); '
				. 'they cannot be bound to a specific department or team by id. '
				. 'Allowed area values: '
				. implode(', ', $allowedAreas)
				. '.',
		);
	}

	/**
	 * @param array<string, list<int>> $valuesByPermission
	 */
	private function validateTeamPermissionCombinations(array $valuesByPermission): void
	{
		foreach ($valuesByPermission as $permissionId => $values)
		{
			if (!PermissionDictionary::isTeamDependentVariablesPermission((string)$permissionId))
			{
				continue;
			}

			$hasNone = in_array(PermissionVariablesDictionary::VARIABLE_NONE, $values, true);
			$hasGranted = (bool)array_filter(
				$values,
				static fn(int $value): bool => $value !== PermissionVariablesDictionary::VARIABLE_NONE,
			);

			if ($hasNone && $hasGranted)
			{
				throw new \DomainException(
					"Invalid team permission combination for '{$permissionId}': "
						. 'NONE cannot be combined with any granting area value.',
				);
			}
		}
	}
}

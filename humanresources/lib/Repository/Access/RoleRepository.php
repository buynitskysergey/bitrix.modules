<?php

namespace Bitrix\HumanResources\Repository\Access;

use Bitrix\HumanResources\Enum\Access\RoleCategory;
use Bitrix\HumanResources\Model\Access\AccessRoleTable;
use Bitrix\HumanResources\Model\Access\EO_AccessRole;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\DeleteResult;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\SystemException;

class RoleRepository
{
	public function getRoleList(?RoleCategory $category = null): array
	{
		$parameters = [
			'select' => ['ID', 'NAME', 'CATEGORY'],
		];

		if ($category)
		{
			$parameters['filter'] =  [
				'=CATEGORY' => $category->value,
			];
		}

		return AccessRoleTable::getList($parameters)->fetchAll();
	}

	/**
	 * @param array<int> $roleIds
	 */
	public function getRolesByIds(array $roleIds, RoleCategory $category): array
	{
		if (empty($roleIds))
		{
			return [];
		}

		$roles = [];
		foreach (array_chunk(array_values(array_unique($roleIds)), 300) as $roleIdsChunk)
		{
			$result = AccessRoleTable::getList([
				'select' => ['ID', 'NAME', 'CATEGORY'],
				'filter' => [
					'@ID' => $roleIdsChunk,
					'=CATEGORY' => $category->value,
				],
			]);
			while ($role = $result->fetch())
			{
				$roles[] = $role;
			}
		}

		return $roles;
	}

	public function getRoleById(int $roleId, RoleCategory $category): ?array
	{
		$role = AccessRoleTable::getList([
			'select' => ['ID', 'NAME', 'CATEGORY'],
			'filter' => [
				'=ID' => $roleId,
				'=CATEGORY' => $category->value,
			],
			'limit' => 1,
		])->fetch();

		return $role ?: null;
	}

	public function create(string $roleName, RoleCategory $category = RoleCategory::Department): AddResult
	{
		return AccessRoleTable::add([
			'NAME' => $roleName,
			'CATEGORY' => $category->value,
		]);
	}

	public function updateName(int $roleId, string $roleName): UpdateResult
	{
		return AccessRoleTable::update($roleId, ['NAME' => $roleName]);
	}

	public function delete(int $roleId): DeleteResult
	{
		return AccessRoleTable::delete($roleId);
	}

	/**
	 * @param array<int> $roleIds
	 */
	public function deleteByIds(array $roleIds): void
	{
		if (empty($roleIds))
		{
			return;
		}

		AccessRoleTable::deleteList(['@ID' => $roleIds]);
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getRoleObjectByNameAndCategory(string $name, RoleCategory $roleCategory): ?EO_AccessRole
	{
		return AccessRoleTable::query()
			->setFilter([
				'=NAME' => $name,
				'=CATEGORY' => $roleCategory->value,
			])
			->fetchObject()
		;
	}

	public function getRoleNameById(int $roleId): ?string
	{
		$role = AccessRoleTable::query()
			->setSelect(['NAME'])
			->where('ID', $roleId)
			->setLimit(1)
			->exec()
			->fetch()
		;

		if ($role && $role['NAME'])
		{
			return $role['NAME'];
		}

		return null;
	}

	public function areRolesDefined(): bool
	{
		return AccessRoleTable::query()->setSelect(['ID'])->setLimit(1)->fetchObject() !== null;
	}
}

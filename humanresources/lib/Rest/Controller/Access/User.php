<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Rest\Controller\Access;

use Bitrix\HumanResources\Access\Permission\Mapper\TeamPermissionMapper;
use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Access\Permission\PermissionHelper;
use Bitrix\HumanResources\Access\Permission\PermissionVariablesDictionary;
use Bitrix\HumanResources\Enum\Access\RoleCategory;
use Bitrix\HumanResources\Internals;
use Bitrix\HumanResources\Rest\RequestParams;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

class User extends RestController
{
	private const CATEGORY_BOTH = 'BOTH';

	public function permissionsAction(): ArrayResponse
	{
		$params = new RequestParams($this->getRequest()->getJsonList());
		$params->assertAllowedFields(['employeeId', 'category']);
		$employeeId = $params->requirePositiveInt('employeeId');
		$categories = $this->requireCategories($params);
		$this->assertCanReadPermissions($employeeId, $categories);

		$result = [];
		foreach ($categories as $category)
		{
			$result[] = [
				'category' => $category->value,
				'permissions' => $this->getPermissionValues($category, $employeeId),
			];
		}

		return new ArrayResponse([
			'employeeId' => $employeeId,
			'categories' => $result,
		]);
	}

	/**
	 * @return list<RoleCategory>
	 */
	private function requireCategories(RequestParams $params): array
	{
		$category = $params->requireNonEmptyString('category');
		if ($category === self::CATEGORY_BOTH)
		{
			return [RoleCategory::Department, RoleCategory::Team];
		}

		$resolvedCategory = RoleCategory::tryFrom($category);
		if ($resolvedCategory === null)
		{
			throw new RequestValidationException([
				new Error(
					'Invalid "category" value. Allowed: DEPARTMENT, TEAM, BOTH.',
					'INVALID_CATEGORY',
				),
			]);
		}

		return [$resolvedCategory];
	}

	/**
	 * @param list<RoleCategory> $categories
	 */
	private function assertCanReadPermissions(int $employeeId, array $categories): void
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		if ($employeeId === $userId)
		{
			$structureView = PermissionHelper::getPermissionValue(
				PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW,
				$userId,
			)->getFirst()?->value ?? PermissionVariablesDictionary::VARIABLE_NONE;
			if ($structureView === PermissionVariablesDictionary::VARIABLE_NONE)
			{
				throw new AccessDeniedException();
			}

			return;
		}

		$accessService = Internals\Service\Container::getAccessService();
		foreach ($categories as $category)
		{
			if (!$accessService->checkAccessToEditPermissions($category, $userId))
			{
				throw new AccessDeniedException();
			}
		}
	}

	/**
	 * @return list<array>
	 */
	private function getPermissionValues(RoleCategory $category, int $employeeId): array
	{
		$permissionIds = $category === RoleCategory::Department
			? PermissionDictionary::getDepartmentCategoryPermissionIds()
			: PermissionDictionary::getTeamCategoryPermissionIds();
		$result = [];
		foreach ($permissionIds as $permissionId)
		{
			$type = PermissionDictionary::getType($permissionId);
			$collection = PermissionHelper::getPermissionValue($permissionId, $employeeId);

			if ($type === PermissionDictionary::TYPE_TOGGLER)
			{
				$result[] = [
					'permissionId' => $permissionId,
					'type' => $type,
					'enabled' => ($collection->getFirst()?->value ?? PermissionDictionary::VALUE_NO)
						=== PermissionDictionary::VALUE_YES,
				];

				continue;
			}

			if ($type === PermissionDictionary::TYPE_DEPENDENT_VARIABLES)
			{
				$mapper = TeamPermissionMapper::createFromCollection($collection);
				$result[] = [
					'permissionId' => $permissionId,
					'type' => $type,
					'teamArea' => $mapper->getTeamPermissionValue(),
					'departmentArea' => $mapper->getDepartmentPermissionValue(),
				];

				continue;
			}

			$result[] = [
				'permissionId' => $permissionId,
				'type' => $type,
				'area' => $collection->getFirst()?->value ?? PermissionVariablesDictionary::VARIABLE_NONE,
			];
		}

		return $result;
	}
}

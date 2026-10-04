<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Rest\Controller\Access;

use Bitrix\HumanResources\Access\Role\RoleDictionary;
use Bitrix\HumanResources\Enum\Access\RoleCategory;
use Bitrix\HumanResources\Internals;
use Bitrix\HumanResources\Rest\Dto\Access\RoleAccessRightDto;
use Bitrix\HumanResources\Rest\Dto\Access\RoleDto;
use Bitrix\HumanResources\Rest\RequestParams;
use Bitrix\HumanResources\Service\Access\RolePermissionService;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(RoleDto::class)]
class Role extends RestController
{
	private const CATEGORY_BOTH = 'BOTH';

	public function listAction(): ListResponse
	{
		$params = $this->getParams(['category']);
		$categories = $this->requireCategories($params, true);
		$this->assertCanManageCategories($categories);

		$collection = new DtoCollection(RoleDto::class);
		$service = Container::getAccessRolePermissionService();
		foreach ($categories as $category)
		{
			foreach ($this->getRoleDtos($service, $category) as $role)
			{
				$collection->add($role);
			}
		}

		return new ListResponse($collection);
	}

	public function getAction(): GetResponse
	{
		$params = $this->getParams(['id', 'category']);
		$category = $this->requireCategory($params);
		$this->assertCanManageCategories([$category]);

		$roleId = $params->requirePositiveInt('id');
		$service = Container::getAccessRolePermissionService();
		$role = $this->requireRoleDto($service, $category, $roleId);

		return new GetResponse($role);
	}

	public function addAction(): ArrayResponse
	{
		$params = $this->getParams(['category', 'title', 'accessRights']);
		$category = $this->requireCategory($params);
		$this->assertCanManageCategories([$category]);

		$title = trim($params->requireNonEmptyString('title'));
		$accessRights = $this->requireAccessRights($params);
		$settings = [[
			'id' => 0,
			'title' => $title,
			'accessRights' => $accessRights,
		]];
		$service = Container::getAccessRolePermissionService();
		$service->setCategory($category);

		try
		{
			$service->saveRolePermissions($settings);
		}
		catch (\DomainException $exception)
		{
			$this->throwDomainValidationError($exception);
		}

		return new ArrayResponse(
			$this->requireRoleDto($service, $category, (int)$settings[0]['id'])->toArray(),
		);
	}

	public function updateAction(): ArrayResponse
	{
		$params = $this->getParams(['id', 'category', 'title', 'accessRights']);
		$category = $this->requireCategory($params);
		$this->assertCanManageCategories([$category]);

		$roleId = $params->requirePositiveInt('id');
		$service = Container::getAccessRolePermissionService();
		$service->setCategory($category);
		$currentRole = $this->requireRoleRow($service, $roleId);
		$title = (string)$currentRole['NAME'];
		if ($params->has('title'))
		{
			$title = trim($params->requireNonEmptyString('title'));
		}

		$settings = [[
			'id' => $roleId,
			'title' => $title,
			'accessRights' => $this->requireAccessRights($params),
		]];

		try
		{
			$service->saveRolePermissions($settings);
		}
		catch (\DomainException $exception)
		{
			$this->throwDomainValidationError($exception);
		}

		return new ArrayResponse($this->requireRoleDto($service, $category, $roleId)->toArray());
	}

	public function deleteAction(): ArrayResponse
	{
		$params = $this->getParams(['id', 'category']);
		$category = $this->requireCategory($params);
		$this->assertCanManageCategories([$category]);

		$roleId = $params->requirePositiveInt('id');
		$service = Container::getAccessRolePermissionService();
		$service->setCategory($category);
		$this->requireRoleRow($service, $roleId);

		try
		{
			$service->deleteRole($roleId);
		}
		catch (\DomainException $exception)
		{
			$this->throwDomainValidationError($exception);
		}

		return new ArrayResponse([
			'id' => $roleId,
			'category' => $category->value,
		]);
	}

	/**
	 * @param list<string> $allowedFields
	 */
	private function getParams(array $allowedFields): RequestParams
	{
		$params = new RequestParams($this->getRequest()->getJsonList());
		$params->assertAllowedFields($allowedFields);

		return $params;
	}

	/**
	 * @return list<RoleCategory>
	 */
	private function requireCategories(RequestParams $params, bool $allowBoth): array
	{
		$category = $params->requireNonEmptyString('category');
		if ($allowBoth && $category === self::CATEGORY_BOTH)
		{
			return [RoleCategory::Department, RoleCategory::Team];
		}

		$resolvedCategory = RoleCategory::tryFrom($category);
		if ($resolvedCategory === null)
		{
			$this->throwValidationError(
				'Invalid "category" value. Allowed: DEPARTMENT, TEAM'
					. ($allowBoth ? ', BOTH.' : '.'),
				'INVALID_CATEGORY',
			);
		}

		return [$resolvedCategory];
	}

	private function requireCategory(RequestParams $params): RoleCategory
	{
		return $this->requireCategories($params, false)[0];
	}

	/**
	 * @param list<RoleCategory> $categories
	 */
	private function assertCanManageCategories(array $categories): void
	{
		$userId = (int)$this->getCurrentUser()?->getId();
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
	 * @return list<array{id: string, value: int}>
	 */
	private function requireAccessRights(RequestParams $params): array
	{
		$rights = $params->requireList('accessRights', true);
		$result = [];
		foreach ($rights as $index => $right)
		{
			if (!is_array($right))
			{
				$this->throwValidationError(
					"Parameter \"accessRights.{$index}\" must contain only permissionId and area.",
					'INVALID_ACCESS_RIGHTS',
				);
			}

			$fieldNames = array_keys($right);
			sort($fieldNames);
			if ($fieldNames !== ['area', 'permissionId'])
			{
				$this->throwValidationError(
					"Parameter \"accessRights.{$index}\" must contain only permissionId and area.",
					'INVALID_ACCESS_RIGHTS',
				);
			}

			$permissionId = $right['permissionId'] ?? null;
			$area = $right['area'] ?? null;
			if (!is_string($permissionId) || $permissionId === '' || !is_int($area))
			{
				$this->throwValidationError(
					"Parameter \"accessRights.{$index}\" has invalid field types.",
					'INVALID_ACCESS_RIGHTS',
				);
			}

			$result[] = [
				'id' => $permissionId,
				'value' => $area,
			];
		}

		return $result;
	}

	/**
	 * @return list<RoleDto>
	 */
	private function getRoleDtos(RolePermissionService $service, RoleCategory $category): array
	{
		$service->setCategory($category);
		$roles = $service->getRoleList();
		$rightsByRoleId = $service->getCanonicalRoleAccessRightsMap(
			array_map(static fn(array $role): int => (int)$role['ID'], $roles),
		);
		$result = [];
		foreach ($roles as $role)
		{
			$roleId = (int)$role['ID'];
			$result[] = $this->mapRoleToDto(
				$role,
				$category,
				$rightsByRoleId[$roleId] ?? [],
			);
		}

		return $result;
	}

	private function requireRoleDto(
		RolePermissionService $service,
		RoleCategory $category,
		int $roleId,
	): RoleDto
	{
		$service->setCategory($category);
		$role = $this->requireRoleRow($service, $roleId);

		return $this->mapRoleToDto($role, $category, $service->getCanonicalRoleAccessRights($roleId));
	}

	private function requireRoleRow(RolePermissionService $service, int $roleId): array
	{
		$role = $service->getRoleById($roleId);
		if ($role !== null)
		{
			return $role;
		}

		$this->throwValidationError('Role not found.', 'ROLE_NOT_FOUND');
	}

	/**
	 * @param array{ID: int|string, NAME: string, CATEGORY?: string} $role
	 * @param list<array{id: string, value: int}> $rights
	 */
	private function mapRoleToDto(array $role, RoleCategory $category, array $rights): RoleDto
	{
		$roleName = (string)$role['NAME'];
		$dto = new RoleDto();
		$dto->id = (int)$role['ID'];
		$dto->title = RoleDictionary::getRoleName($roleName);
		$dto->category = $category->value;
		$dto->isDefault = isset(RoleDictionary::getConstants()[$roleName]);
		$dto->accessRights = new DtoCollection(RoleAccessRightDto::class);

		foreach ($rights as $right)
		{
			$rightDto = new RoleAccessRightDto();
			$rightDto->permissionId = $right['id'];
			$rightDto->area = $right['value'];
			$dto->accessRights->add($rightDto);
		}

		return $dto;
	}

	private function throwDomainValidationError(\DomainException $exception): never
	{
		$this->throwValidationError($exception->getMessage(), 'ROLE_VALIDATION');
	}

	private function throwValidationError(string $message, string $field): never
	{
		throw new RequestValidationException([new Error($message, $field)]);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Rest\Controller\Access;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Enum\Access\RoleCategory;
use Bitrix\HumanResources\Internals;
use Bitrix\HumanResources\Rest\RequestParams;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

class Permission extends RestController
{
	private const CATEGORY_BOTH = 'BOTH';

	public function listAction(): ArrayResponse
	{
		$params = new RequestParams($this->getRequest()->getJsonList());
		$params->assertAllowedFields(['category']);
		$categories = $this->requireCategories($params);
		$this->assertCanManageCategories($categories);

		$service = Container::getAccessRolePermissionService();
		$result = [];
		foreach ($categories as $category)
		{
			$service->setCategory($category);
			$result[] = [
				'category' => $category->value,
				'sections' => $this->mapSections($service->getAccessRights()),
			];
		}

		return new ArrayResponse(['categories' => $result]);
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
	 * @param list<array{
	 *     sectionTitle: string,
	 *     sectionCode: string,
	 *     sectionIcon?: array,
	 *     rights: list<array{
	 *         id: string,
	 *         title: string,
	 *         hint: ?string,
	 *         type: string,
	 *         variables: list<array{id: int, title: ?string}>,
	 *         minValue?: int,
	 *         maxValue?: int
	 *     }>
	 * }> $sections
	 *
	 * @return list<array>
	 */
	private function mapSections(array $sections): array
	{
		$result = [];
		foreach ($sections as $section)
		{
			$permissions = [];
			foreach ($section['rights'] as $right)
			{
				$type = $right['type'];
				$allowedValues = $type === PermissionDictionary::TYPE_TOGGLER
					? [
						['value' => PermissionDictionary::VALUE_NO, 'title' => 'no'],
						['value' => PermissionDictionary::VALUE_YES, 'title' => 'yes'],
					]
					: array_map(
						static fn(array $variable): array => [
							'value' => (int)$variable['id'],
							'title' => (string)($variable['title'] ?? ''),
						],
						$right['variables'],
					);

				$permission = [
					'permissionId' => (string)$right['id'],
					'title' => (string)$right['title'],
					'hint' => (string)($right['hint'] ?? ''),
					'type' => (string)$type,
					'allowedValues' => $allowedValues,
					'minValue' => (int)($right['minValue'] ?? PermissionDictionary::getMinValueByTypeOrNull($type)),
					'maxValue' => (int)($right['maxValue'] ?? PermissionDictionary::getMaxValueByTypeOrNull($type)),
				];
				$permissions[] = $permission;
			}

			$mappedSection = [
				'code' => (string)$section['sectionCode'],
				'title' => (string)$section['sectionTitle'],
				'permissions' => $permissions,
			];
			if (isset($section['sectionIcon']))
			{
				$mappedSection['icon'] = $section['sectionIcon'];
			}

			$result[] = $mappedSection;
		}

		return $result;
	}
}

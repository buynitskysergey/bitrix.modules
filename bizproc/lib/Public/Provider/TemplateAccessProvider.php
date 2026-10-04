<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider;

use Bitrix\Bizproc\Internal\Access\AccessController;
use Bitrix\Bizproc\Internal\Access\Model\UserModel;
use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Model\PermissionTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlExpression;

/**
 * Data-level companion of {@see \Bitrix\Bizproc\Public\Service\TemplateAccessService}: turns a user's
 * effective permission scope into an ORM filter over WorkflowTemplateTable.ID. The grid and the REST
 * list both mix in this filter so they narrow to the same area from a single source of truth.
 */
class TemplateAccessProvider
{
	private const INLINE_SCOPE_LIMIT = 100;

	/** Fail-closed filter: matches no template (IDs are positive) and never widens to "all". */
	public const FAIL_CLOSED_FILTER = ['=ID' => -1];

	/**
	 * ORM filter selecting the templates the user effectively has $permission on:
	 *  - portal admin / scope "all" (-1) => [] (no ID restriction);
	 *  - small selective scope => ['@ID' => [t1, t2, ...]];
	 *  - large selective scope => ['@ID' => ACL subquery];
	 *  - empty scope (no role, or 0 selected templates) => FAIL_CLOSED_FILTER (never "all").
	 *
	 * For EDIT the scope also unions PUBLISH (publish dominates edit) so list visibility stays in sync
	 * with {@see TemplateAccessService::canEdit()}.
	 *
	 * @throws ArgumentException for a toggler permission (e.g. CREATE) which carries no template scope.
	 */
	public function getEntityFilter(?int $userId, int $permission = PermissionDictionary::BIZPROC_TEMPLATE_EDIT): array
	{
		$descriptor = PermissionDictionary::getPermission((string)$permission);
		if (($descriptor['type'] ?? null) === PermissionDictionary::TYPE_TOGGLER)
		{
			throw new ArgumentException('Entity filter is not applicable to a toggler permission: ' . $permission);
		}

		$controller = $this->controller($userId);
		if ($controller->isAdmin())
		{
			return [];
		}

		$scope = $this->resolveScope($controller, $permission);
		if (empty($scope))
		{
			return self::FAIL_CLOSED_FILTER;
		}

		if (in_array(PermissionDictionary::VALUE_VARIATION_ALL, $scope, true))
		{
			return [];
		}

		return $this->getSelectiveScopeFilter($controller, $scope, $permission);
	}

	/**
	 * @return int[] effective scope values; [-1] means "all"; [] means empty / no scope.
	 */
	private function resolveScope(AccessController $controller, int $permission): array
	{
		/** @var UserModel $user */
		$user = $controller->getUser();

		$permissions = $this->getScopePermissions($permission);

		$merged = [];
		foreach ($permissions as $code)
		{
			$scope = $user->getPermissionMulti((string)$code);
			if ($scope === null)
			{
				continue;
			}

			if (in_array(PermissionDictionary::VALUE_VARIATION_ALL, $scope, true))
			{
				return [PermissionDictionary::VALUE_VARIATION_ALL];
			}

			foreach ($scope as $value)
			{
				$merged[$value] = $value;
			}
		}

		return array_values($merged);
	}

	private function getSelectiveScopeFilter(AccessController $controller, array $scope, int $permission): array
	{
		if (count($scope) <= self::INLINE_SCOPE_LIMIT)
		{
			return ['@ID' => array_values($scope)];
		}

		$roleIds = $this->getRoleIds($controller);
		if (empty($roleIds))
		{
			return self::FAIL_CLOSED_FILTER;
		}

		$subQuery = PermissionTable::query()
			->setSelect(['VALUE'])
			->whereIn('ROLE_ID', $roleIds)
			->whereIn('PERMISSION_ID', $this->getScopePermissions($permission))
			->where('VALUE', '>', 0)
		;

		return ['@ID' => new SqlExpression($subQuery->getQuery())];
	}

	private function getScopePermissions(int $permission): array
	{
		return $permission === PermissionDictionary::BIZPROC_TEMPLATE_EDIT
			? [PermissionDictionary::BIZPROC_TEMPLATE_EDIT, PermissionDictionary::BIZPROC_TEMPLATE_PUBLISH]
			: [$permission];
	}

	/** @return int[] */
	protected function getRoleIds(AccessController $controller): array
	{
		/** @var UserModel $user */
		$user = $controller->getUser();

		return array_values(array_unique(array_filter(
			array_map('intval', $user->getRoles()),
			static fn (int $roleId): bool => $roleId > 0,
		)));
	}

	protected function controller(?int $userId): AccessController
	{
		return $userId === null
			? AccessController::getCurrent()
			: AccessController::getInstance($userId);
	}
}

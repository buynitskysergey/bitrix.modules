<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service;

use Bitrix\Bizproc\Internal\Access\AccessController;
use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

/**
 * Gate for the rights-configuration surface. There is no scoped delegation: holding the CONFIGURE_RIGHTS
 * toggler (or being a portal administrator) grants FULL access to the rights matrix — any right, every
 * role, every template, including granting CONFIGURE_RIGHTS itself. Anyone else is denied (fail-closed).
 * Every write channel (UI command, REST, import) runs this gate before touching a row.
 */
class DelegationScopeValidator
{
	public const ERROR_ACCESS_DENIED = 'BIZPROC_ACCESS_CONFIGURE_DENIED';

	/**
	 * @param array<int, mixed> $userGroups desired role state (the gate is binary, so the payload is unused)
	 * @param array<int, int|string> $deletedUserGroups role ids to delete (unused: the gate is binary)
	 */
	public function validate(int $userId, array $userGroups = [], array $deletedUserGroups = []): Result
	{
		$result = new Result();
		if ($this->canConfigure($userId))
		{
			return $result;
		}

		$result->addError(new Error(
			(string)Loc::getMessage('BIZPROC_ACCESS_CONFIGURE_DENIED'),
			self::ERROR_ACCESS_DENIED,
		));

		return $result;
	}

	/**
	 * Roles the user may see and edit on the permissions page: all of them for a configuring user (toggler
	 * holder or admin), none otherwise. Read side and write side share this one predicate.
	 *
	 * @param int[] $roleIds
	 * @return int[] $roleIds unchanged for a configuring user, [] otherwise
	 */
	public function filterTouchableRoleIds(int $userId, array $roleIds): array
	{
		return $this->canConfigure($userId) ? $roleIds : [];
	}

	/**
	 * Whether the user may configure rights at all: the CONFIGURE_RIGHTS toggler or a portal administrator.
	 * The single gate source shared by the read side (role list, "all" selectability) and the write side.
	 * Overridable seam so unit tests can inject the answer without the ORM.
	 */
	public function canConfigure(int $userId): bool
	{
		return AccessController::getInstance($userId)->check(
			(string)PermissionDictionary::BIZPROC_TEMPLATE_CONFIGURE_RIGHTS,
		);
	}
}

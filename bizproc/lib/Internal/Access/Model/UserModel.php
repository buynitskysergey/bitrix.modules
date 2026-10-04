<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Access\Model;

use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Access\PermissionCache;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Main\Access\User\UserModel as MainUserModel;

class UserModel extends MainUserModel
{
	private ?array $permissions = null;

	public function getRoles(): array
	{
		if ($this->roles === null)
		{
			$this->roles = [];
			if ($this->userId === 0 || empty($this->getAccessCodes()))
			{
				return $this->roles;
			}

			$this->roles = Container::getAccessRepository()->getRoleIdsByAccessCodes($this->getAccessCodes());
		}

		return $this->roles;
	}

	public function getPermission(string $permissionId): ?int
	{
		$permissions = $this->getPermissions();
		$value = $permissions[$permissionId] ?? null;

		if (is_array($value))
		{
			return isset($value[0]) ? (int)$value[0] : null;
		}

		return $value === null ? null : (int)$value;
	}

	/**
	 * Effective scope of a multivariables permission: `[-1]` for admins and roles with the «all» scope
	 * (`-1` dominates), otherwise the union of `VALUE` rows across the user roles. This is the basis of
	 * the delegate scope used by the configure-rights validator (Q-002).
	 */
	public function getPermissionMulti(string $permissionId): ?array
	{
		if ($this->isAdmin())
		{
			return [PermissionDictionary::VALUE_VARIATION_ALL];
		}

		$permissions = $this->getPermissions();
		$value = $permissions[$permissionId] ?? null;

		return is_array($value) ? $value : null;
	}

	/**
	 * Effective permission layout, read through the tagged {@see PermissionCache}. The heavy role/permission
	 * merge and its `-1` domination live in the cache; the per-hit static memo here just avoids re-entering it.
	 */
	private function getPermissions(): array
	{
		$this->permissions ??= PermissionCache::getInstance()->getEffective($this->userId, $this->getAccessCodes());

		return $this->permissions;
	}
}

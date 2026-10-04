<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Access;

use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;

/**
 * Tagged cache of a user's EFFECTIVE permission layout — the answer to an access check, not the
 * intermediate roles. The value is `eff = { permissionId => -1 | int[] templateIds | int togglerValue }`
 * with the same shape the rule/facade consume (`-1` dominates a multivariables scope).
 *
 * Invalidation is by tag, not by TTL (ALG-02):
 *  - each cached user entry registers a tag for EVERY role it carries (`bizproc_access_role_{r}`), so
 *    {@see self::clearByRole()} on a role reset drops every carrier of that role without enumerating them
 *    (the reverse mapping is kept by `b_cache_tag`);
 *  - the user's access codes go into the cache KEY, so a department/group change re-keys the entry and it
 *    recomputes without a `main` hook;
 *  - structural changes (role create/delete, relation change, orphan VALUE cleanup) drop the global tag.
 *
 * The delegation scope validator must NOT read through this cache (anti-stale / TOCTOU): it uses
 * {@see self::computeEffective()} which reads the committed state directly.
 */
final class PermissionCache
{
	private const TTL = 604800; // 86400 * 7 — big: invalidation is by event, not by time.
	private const GLOBAL_TAG = 'bizproc_access';
	private const ROLE_TAG_PREFIX = 'bizproc_access_role_';

	private static ?self $instance = null;

	public static function getInstance(): self
	{
		self::$instance ??= new self();

		return self::$instance;
	}

	/**
	 * Cached effective layout for a user. An empty layout (no roles) is cached too.
	 *
	 * @param string[] $accessCodes the user's own access codes (membership); part of the cache key.
	 * @return array<int, int|int[]> effective layout keyed by permission id.
	 */
	public function getEffective(int $userId, array $accessCodes): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$cache = Cache::createInstance();
		$dir = self::cacheDir($userId);
		$key = self::cacheKey($userId, $accessCodes);

		if ($cache->initCache(self::TTL, $key, $dir))
		{
			return (array)($cache->getVars()['eff'] ?? []);
		}

		[$roles, $eff] = $this->readRolesAndEffective($accessCodes);

		if ($cache->startDataCache())
		{
			$taggedCache = Application::getInstance()->getTaggedCache();
			$taggedCache->startTagCache($dir);
			foreach ($roles as $roleId)
			{
				$taggedCache->registerTag(self::ROLE_TAG_PREFIX . $roleId);
			}
			$taggedCache->registerTag(self::GLOBAL_TAG);
			$taggedCache->endTagCache();

			$cache->endDataCache(['eff' => $eff]);
		}

		return $eff;
	}

	/**
	 * Uncached effective layout straight from the committed state. Used by the delegation scope validator
	 * so a narrowed scope is seen even while {@see PermissionCache} still holds the wide one.
	 *
	 * @param string[] $accessCodes
	 * @return array<int, int|int[]>
	 */
	public function computeEffective(array $accessCodes): array
	{
		return $this->readRolesAndEffective($accessCodes)[1];
	}

	/** Reset every cache entry that carries role $roleId (matrix change or rename). */
	public function clearByRole(int $roleId): void
	{
		Application::getInstance()->getTaggedCache()->clearByTag(self::ROLE_TAG_PREFIX . $roleId);
	}

	/** Reset every bizproc-access entry (role create/delete, relation change, orphan VALUE cleanup). */
	public function clearGlobal(): void
	{
		Application::getInstance()->getTaggedCache()->clearByTag(self::GLOBAL_TAG);
	}

	/**
	 * @param string[] $accessCodes
	 * @return array{0: int[], 1: array<int, int|int[]>} [roleIds, effectiveLayout]
	 */
	private function readRolesAndEffective(array $accessCodes): array
	{
		$roles = $this->readRoles($accessCodes);

		return [$roles, $this->mergePermissions($roles)];
	}

	/**
	 * @param string[] $accessCodes
	 * @return int[]
	 */
	private function readRoles(array $accessCodes): array
	{
		return Container::getAccessRepository()->getRoleIdsByAccessCodes($accessCodes);
	}

	/**
	 * @param int[] $roleIds
	 * @return array<int, int|int[]> multivariables → int[] (`-1` dominates), toggler → max int value.
	 */
	private function mergePermissions(array $roleIds): array
	{
		$permissions = [];
		$scopeValues = [];
		if (empty($roleIds))
		{
			return $permissions;
		}

		foreach (Container::getAccessRepository()->getPermissionsByRoleIds($roleIds) as $permission)
		{
			$permissionId = $permission->getPermissionId();
			$value = $permission->getValue();

			$descriptor = PermissionDictionary::getPermission((string)$permissionId);
			if (($descriptor['type'] ?? null) === PermissionDictionary::TYPE_MULTIVARIABLES)
			{
				if (isset($scopeValues[$permissionId][PermissionDictionary::VALUE_VARIATION_ALL]))
				{
					continue;
				}

				if ($value === PermissionDictionary::VALUE_VARIATION_ALL)
				{
					$scopeValues[$permissionId] = [PermissionDictionary::VALUE_VARIATION_ALL => true];
				}
				else
				{
					$scopeValues[$permissionId][$value] = true;
				}
			}
			else
			{
				$current = (int)($permissions[$permissionId] ?? 0);
				$permissions[$permissionId] = max($value, $current);
			}
		}

		foreach ($scopeValues as $permissionId => $values)
		{
			$permissions[$permissionId] = array_map('intval', array_keys($values));
		}

		return $permissions;
	}

	private static function cacheDir(int $userId): string
	{
		return '/bizproc/access/user/' . substr(md5('user_' . $userId), 2, 2) . '/user_' . $userId . '/';
	}

	/**
	 * @param string[] $accessCodes
	 */
	private static function cacheKey(int $userId, array $accessCodes): string
	{
		$codes = $accessCodes;
		sort($codes);

		return 'bp_access_user_' . $userId . '_' . md5(implode(',', $codes));
	}
}

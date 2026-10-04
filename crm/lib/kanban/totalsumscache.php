<?php

namespace Bitrix\Crm\Kanban;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

/**
 * Short-TTL per-user cache for kanban-summary aggregates.
 *
 * Caches the raw output of {@see Entity::doGetDataToCalculateTotalSums()}.
 * Backend: managed cache of the application (in-request static cache, deferred
 * write on finalize, protection against concurrent clean/write races).
 *
 * Key layout:
 *  - dir `crm/ktot/{entityTypeId}/{userId}` is the invalidation unit: a single
 *    {@see self::cleanDir()} drops the whole per-user slice across all
 *    categories. The dir is flat on purpose: key-value cache engines
 *    (redis/memcached) invalidate by the exact dir string, so entries put
 *    under nested subdirs would not be covered by the cleanup.
 *  - uniqueId carries categoryId and md5(fieldSum|filter|runtime).
 *
 * Invalidation strategy:
 *  - TTL (default 20s, configurable via option `crm.kanban_total_sums_cache.ttl`).
 *  - Targeted {@see self::cleanDir()} on user actions in the crm.kanban component.
 *
 * Bypass conditions (cache fully skipped):
 *  - Filter contains a key matching `/^[!=@<>]*ID$/` on any nesting level
 *    (PK lookup, not a repeated query).
 *
 * Disabled by default; set option `crm.kanban_total_sums_cache.enabled` = 'Y' to enable.
 *
 * @internal Designed for usage from {@see Entity::getDataToCalculateTotalSums()} only.
 */
final class TotalSumsCache
{
	private const OPT_MODULE = 'crm';
	private const OPT_ENABLED = 'kanban_total_sums_cache.enabled';
	private const OPT_TTL = 'kanban_total_sums_cache.ttl';
	private const DEFAULT_TTL = 20;

	public function isEnabled(): bool
	{
		return Option::get(self::OPT_MODULE, self::OPT_ENABLED, 'N') === 'Y';
	}

	public function get(
		int $entityTypeId,
		int $userId,
		int $categoryId,
		string $fieldSum,
		array $filter,
		array $runtime
	): ?array
	{
		if ($this->isBypass($filter))
		{
			return null;
		}

		$managedCache = Application::getInstance()->getManagedCache();
		$uniqueId = $this->buildUniqueId($entityTypeId, $userId, $categoryId, $fieldSum, $filter, $runtime);

		if ($managedCache->read($this->getTtl(), $uniqueId, $this->buildDir($entityTypeId, $userId)))
		{
			$vars = $managedCache->get($uniqueId);
			if (is_array($vars))
			{
				return $vars;
			}
		}

		return null;
	}

	public function set(
		int $entityTypeId,
		int $userId,
		int $categoryId,
		string $fieldSum,
		array $filter,
		array $runtime,
		array $data
	): void
	{
		if ($this->isBypass($filter))
		{
			return;
		}

		// ManagedCache::set() takes effect only after read() has registered the
		// entry; the caching wrapper always calls get() before set().
		Application::getInstance()->getManagedCache()->set(
			$this->buildUniqueId($entityTypeId, $userId, $categoryId, $fieldSum, $filter, $runtime),
			$data
		);
	}

	public function cleanDir(int $entityTypeId, int $userId): void
	{
		Application::getInstance()->getManagedCache()->cleanDir($this->buildDir($entityTypeId, $userId));
	}

	private function isBypass(array $filter): bool
	{
		foreach ($filter as $k => $value)
		{
			if (preg_match('/^[!=@<>]*ID$/', (string)$k))
			{
				return true;
			}

			// PK conditions may sit inside nested blocks (e.g. LOGIC => OR)
			if (is_array($value) && $this->isBypass($value))
			{
				return true;
			}
		}
		return false;
	}

	private function getTtl(): int
	{
		$ttl = (int)Option::get(self::OPT_MODULE, self::OPT_TTL, (string)self::DEFAULT_TTL);

		// Non-positive TTL would silently disable the cache while isEnabled()
		// still reports 'Y'; disabling is the job of the enabled option alone.
		return $ttl > 0 ? $ttl : self::DEFAULT_TTL;
	}

	private function buildUniqueId(
		int $entityTypeId,
		int $userId,
		int $categoryId,
		string $fieldSum,
		array $filter,
		array $runtime
	): string
	{
		// All key components are present in the uniqueId itself: the managed
		// cache memoizes values by uniqueId alone, regardless of the dir.
		return 'crm|ktot|' . $entityTypeId . '|' . $userId . '|' . $categoryId
			. '|' . md5($fieldSum . '|' . serialize($filter) . '|' . serialize($runtime));
	}

	private function buildDir(int $entityTypeId, int $userId): string
	{
		// Flat dir = the invalidation unit. Key-value cache engines invalidate
		// by the exact dir string, so no nested levels are allowed here.
		return "crm/ktot/{$entityTypeId}/{$userId}";
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Pilot;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\ManagedCache;

/**
 * A portal-wide answer about the pilot. A durable module option says whether its source can contain any
 * value; the detailed answer is kept in managed cache only for the non-empty branch.
 *
 * Lifecycle writers rebuild the option from storage after their transaction instead of shifting a
 * counter.
 * Repeated publication, repeated stop and rollback therefore cannot accumulate an arithmetic drift. The
 * common empty branch returns before managed cache and storage, including immediately after invalidation.
 *
 * Two readers competing with a writer are handled by the kernel rather than by a short lifetime: a write
 * whose read began before the drop is refused ({@see ManagedCache::setImmediate()}), so a reader that
 * computed the state of a moment already gone cannot store it.
 */
abstract class PilotPortalCache
{
	private const MODULE_ID = 'bizproc';
	private const PRESENT_VALUE = 'Y';
	private const ABSENT_VALUE = 'N';

	/**
	 * The lifetime is a backstop and not the way the value is kept fresh - every path that changes the
	 * storage drops it explicitly.
	 */
	protected const CACHE_TTL = 86400;

	private ?ManagedCache $managedCache = null;

	/** @var array<string, bool> */
	private static array $knownPresence = [];

	/**
	 * Drops the stored answer: called by whatever changed the storage behind it. Cheap enough for the
	 * paths that call it, all of which already write to the database.
	 */
	public function invalidate(): void
	{
		unset(self::$knownPresence[$this->presenceOptionName()]);
		$this->cache()->clean($this->cacheId());
	}

	/**
	 * Restores the durable empty-state guard from the source of truth after the storage transaction commits.
	 */
	public function synchronize(): void
	{
		$value = $this->readFromStorage();
		$isPresent = $this->hasStoredValue($value);

		Option::set(
			self::MODULE_ID,
			$this->presenceOptionName(),
			$isPresent ? self::PRESENT_VALUE : self::ABSENT_VALUE,
			'',
		);
	}

	/**
	 * The id of the value in the cache. Constant by design: a key that varies per call never hits.
	 */
	abstract protected function cacheId(): string;

	abstract protected function presenceOptionName(): string;

	/** @return array<string, mixed> */
	abstract protected function emptyValue(): array;

	/** @param array<string, mixed> $value */
	abstract protected function hasStoredValue(array $value): bool;

	/**
	 * The answer as the storage has it - the value the cache is a copy of.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function readFromStorage(): array;

	/**
	 * @return array<string, mixed>
	 */
	protected function value(): array
	{
		$optionName = $this->presenceOptionName();
		if (!array_key_exists($optionName, self::$knownPresence))
		{
			self::$knownPresence[$optionName] = Option::get(
				self::MODULE_ID,
				$optionName,
				self::ABSENT_VALUE,
			) === self::PRESENT_VALUE;
		}

		if (!self::$knownPresence[$optionName])
		{
			return $this->emptyValue();
		}

		$cache = $this->cache();
		$cacheId = $this->cacheId();

		if ($cache->read(static::CACHE_TTL, $cacheId))
		{
			$stored = $cache->get($cacheId);
			if (is_array($stored))
			{
				return $stored;
			}
		}

		$value = $this->readFromStorage();
		$cache->setImmediate($cacheId, $value);

		return $value;
	}

	/**
	 * The kernel keeps one instance per hit and reads it into memory on the first demand, so the second
	 * question of the same hit costs nothing at all.
	 */
	private function cache(): ManagedCache
	{
		return $this->managedCache ??= Application::getInstance()->getManagedCache();
	}
}

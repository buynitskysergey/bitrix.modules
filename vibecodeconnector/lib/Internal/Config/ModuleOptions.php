<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Config;

use Bitrix\Main\Config\Option;

/**
 * The single access point to the options of this module. Options owned by other modules are out of
 * scope: each of them has its own named class that reads the platform directly.
 *
 * Every operation is scoped globally and explicitly, so the site layer is never read implicitly.
 * Stored values are memoized per request and shared by all instances: a write is immediately
 * visible to every reader of the same request, no matter whether the platform managed to drop
 * its own option cache.
 */
final class ModuleOptions
{
	private const MODULE_ID = 'vibecodeconnector';
	private const GLOBAL_SITE_ID = '';

	/**
	 * Stored values per request, keyed by option name; null means nothing is stored.
	 *
	 * @var array<string, string|null>
	 */
	private static array $valueMemo = [];

	/**
	 * The request memory already tells "not read yet" from "nothing is stored", so it answers on its
	 * own: falling back to Option::get() here would mean reading the very platform cache this class
	 * exists to bypass, and a value deleted in this request would come back from a stale cache file.
	 */
	public function get(string $name, string $default = ''): string
	{
		return $this->getStoredValue($name) ?? $default;
	}

	public function has(string $name): bool
	{
		return $this->getStoredValue($name) !== null;
	}

	public function set(string $name, string $value): void
	{
		Option::set(self::MODULE_ID, $name, $value, self::GLOBAL_SITE_ID);
		self::$valueMemo[$this->getMemoKey($name)] = $value;
	}

	public function delete(string $name): void
	{
		Option::delete(self::MODULE_ID, ['name' => $name, 'site_id' => self::GLOBAL_SITE_ID]);
		self::$valueMemo[$this->getMemoKey($name)] = null;
	}

	private function getStoredValue(string $name): ?string
	{
		$key = $this->getMemoKey($name);
		if (!array_key_exists($key, self::$valueMemo))
		{
			self::$valueMemo[$key] = Option::getRealValue(self::MODULE_ID, $name, self::GLOBAL_SITE_ID);
		}

		return self::$valueMemo[$key];
	}

	/**
	 * Option names are case-insensitive for the platform, so the request memory has to be keyed the
	 * same way.
	 */
	private function getMemoKey(string $name): string
	{
		return mb_strtolower($name);
	}
}

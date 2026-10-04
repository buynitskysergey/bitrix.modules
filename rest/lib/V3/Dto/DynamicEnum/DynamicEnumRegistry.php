<?php

namespace Bitrix\Rest\V3\Dto\DynamicEnum;

use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\CacheManager;

final class DynamicEnumRegistry
{
	private const CACHE_KEY_PREFIX = 'rest.v3.dynamic_enum.';
	private const VALUES_CACHE_DIR = 'rest/v3.0.4/dynamic_enum';
	private const GENERATION_CACHE_KEY = 'rest.v3.dynamic_enum.__generation';

	/** @var array<string, DynamicEnumDefinition> */
	private static array $runtimeCache = [];

	public static function resolve(string $providerClass): DynamicEnumDefinition
	{
		self::assertProviderClass($providerClass);

		if (isset(self::$runtimeCache[$providerClass]))
		{
			return self::$runtimeCache[$providerClass];
		}

		$cacheKey = self::cacheKey($providerClass);
		$cached = CacheManager::getWithDir($cacheKey, self::VALUES_CACHE_DIR);
		if (is_array($cached) && isset($cached['type'], $cached['values']) && is_array($cached['values']))
		{
			$type = DynamicEnumType::tryFrom($cached['type']);
			if ($type !== null)
			{
				$definition = new DynamicEnumDefinition($type, $cached['values']);
				self::$runtimeCache[$providerClass] = $definition;

				return $definition;
			}
		}

		$definition = self::loadFromProvider($providerClass);
		CacheManager::setWithDir($cacheKey, [
			'type' => $definition->type->value,
			'values' => $definition->values,
		], self::VALUES_CACHE_DIR);
		self::$runtimeCache[$providerClass] = $definition;

		return $definition;
	}

	public static function clear(?string $providerClass = null): void
	{
		if ($providerClass !== null)
		{
			self::assertProviderClass($providerClass);
			CacheManager::deleteWithDir(self::cacheKey($providerClass), self::VALUES_CACHE_DIR);
			unset(self::$runtimeCache[$providerClass]);
			self::bumpGeneration();

			return;
		}

		CacheManager::cleanDirectory(self::VALUES_CACHE_DIR);
		self::$runtimeCache = [];
		self::bumpGeneration();
	}

	/**
	 * Bumped on every clear() so OpenAPI documentation can detect stale enum values.
	 */
	public static function getGeneration(): int
	{
		$generation = CacheManager::get(self::GENERATION_CACHE_KEY);

		return is_int($generation) ? $generation : 0;
	}

	private static function bumpGeneration(): void
	{
		CacheManager::set(self::GENERATION_CACHE_KEY, self::getGeneration() + 1);
	}

	private static function loadFromProvider(string $providerClass): DynamicEnumDefinition
	{
		/** @var DynamicEnumProvider $provider */
		$provider = new $providerClass();
		$type = $provider->getType();
		$values = $provider->getValues();

		foreach ($values as $value)
		{
			$ok = match ($type)
			{
				DynamicEnumType::String => is_string($value),
				DynamicEnumType::Int => is_int($value),
			};
			if (!$ok)
			{
				throw new SystemException(
					'DynamicEnumProvider returned value incompatible with type '
					. $type->value . ': ' . $providerClass
				);
			}
		}

		/** @var list<string|int> $values */
		return new DynamicEnumDefinition($type, array_values($values));
	}

	private static function assertProviderClass(string $providerClass): void
	{
		if (!is_a($providerClass, DynamicEnumProvider::class, true))
		{
			throw new SystemException(
				'Dynamic enum provider must implement DynamicEnumProvider: ' . $providerClass
			);
		}
	}

	private static function cacheKey(string $providerClass): string
	{
		return self::CACHE_KEY_PREFIX . md5($providerClass);
	}
}

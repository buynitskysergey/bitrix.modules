<?php

namespace Bitrix\Crm\Ads\Pixel\ConversionEventTriggers\Vk;

use Bitrix\Main\Data\Cache;

final class SignalCooldown
{
	private const CACHE_TTL = 600;
	private const CACHE_KEY_PREFIX = 'vkads_conversion:v1';
	private const CACHE_DIR = '/crm/vkads_conversion';

	public function __construct(
		private ?Cache $cache = null,
	)
	{
	}

	public function allowByCooldown(
		int $entityTypeId,
		int $entityId,
		string $current,
		string $target,
		string $eventName,
		string $eventStatus,
	): bool
	{
		if ($current !== $target)
		{
			return false;
		}

		$cacheId = $this->getCacheId(
			$entityTypeId,
			$entityId,
			$eventName,
			$eventStatus,
		);
		try
		{
			$cache = $this->cache ?? Cache::createInstance();
			if ($cache->initCache(self::CACHE_TTL, $cacheId, self::CACHE_DIR))
			{
				return false;
			}

			if (!$cache->startDataCache())
			{
				return false;
			}

			$cache->endDataCache(true);

			return true;
		}
		catch (\Throwable)
		{
			return true;
		}
	}

	public function reset(
		int $entityTypeId,
		int $entityId,
		string $eventName,
		string $eventStatus,
	): void
	{
		try
		{
			($this->cache ?? Cache::createInstance())->clean(
				$this->getCacheId($entityTypeId, $entityId, $eventName, $eventStatus),
				self::CACHE_DIR,
			);
		}
		catch (\Throwable)
		{
		}
	}

	private function getCacheId(
		int $entityTypeId,
		int $entityId,
		string $eventName,
		string $eventStatus,
	): string
	{
		return implode(
			':',
			[
				self::CACHE_KEY_PREFIX,
				$entityTypeId,
				$entityId,
				$eventName,
				$eventStatus,
			],
		);
	}
}

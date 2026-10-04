<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Rest\V3;

use Bitrix\Main\Loader;

/**
 * @internal
 */
class CacheManager
{
	public static function cleanAll(): void
	{
		if (static::isRestAvailable())
		{
			\Bitrix\Rest\V3\CacheManager::cleanAll();
		}
	}

	protected static function isRestAvailable(): bool
	{
		return
			Loader::includeModule('rest')
			&& class_exists('\\Bitrix\\Rest\\V3\\CacheManager')
		;
	}
}

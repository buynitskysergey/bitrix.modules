<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item;

/**
 * Cache backend for {@see AbstractItemProvider::cached()} — where read results are stored
 * and how long they live.
 */
enum CacheMode: string
{
	/**
	 * In-memory cache living only for the current request. Fast, no serialization, dropped
	 * when the request ends. Suited for repeated reads of the same items within one hit
	 * (mass processing, relation rendering).
	 */
	case Runtime = 'runtime';
}

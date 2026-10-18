<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\RateLimit;

use Bitrix\Main\Data\Cache;

/**
 * Per-user sliding-window rate limiter for the AI-assistant BI tools.
 *
 * Every call to any tool extending {@see \Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\BaseBiTool}
 * (both read and write) is counted against the same window. A user may make at
 * most {@see self::MAX_REQUESTS} calls within any {@see self::WINDOW_SECONDS}
 * window; the (MAX_REQUESTS + 1)-th call inside the window is rejected without
 * being registered, and the caller is told how many seconds to wait until the
 * oldest counted call leaves the window.
 *
 * The window state (a list of Unix timestamps) is stored in
 * {@see \Bitrix\Main\Data\Cache} with a TTL equal to the window, mirroring
 * {@see \Bitrix\AiAssistant\External\AiNode\Limiter\RateLimiter}.
 */
class BiToolsRateLimiter
{
	public const WINDOW_SECONDS = 60;
	public const MAX_REQUESTS = 60;

	private const CACHE_DIR = '/biconnector/ai_tools_ratelimit/';

	/**
	 * Registers the current call for the given user and reports whether it is
	 * allowed under the sliding window.
	 *
	 * @param int $userId User whose calls are being counted.
	 * @return array{allowed: bool, retryAfter: int} `allowed` is false when the
	 *         limit is exceeded; `retryAfter` is the number of seconds until the
	 *         oldest counted call leaves the window (0 when allowed).
	 */
	public function consume(int $userId): array
	{
		$key = $this->getCacheKey($userId);
		$cacheDir = $this->getCacheDir($userId);
		$now = time();

		$timestamps = self::filterWithinWindow($this->read($key, $cacheDir), $now);

		if (count($timestamps) >= self::MAX_REQUESTS)
		{
			return [
				'allowed' => false,
				'retryAfter' => self::retryAfter($timestamps, $now),
			];
		}

		$timestamps[] = $now;
		$this->write($key, $cacheDir, $timestamps);

		return [
			'allowed' => true,
			'retryAfter' => 0,
		];
	}

	/**
	 * Drops timestamps that fall outside the current window.
	 *
	 * Pure helper, extracted so the windowing logic can be unit-tested without a
	 * cache backend.
	 *
	 * @param int[] $timestamps
	 * @param int $now
	 * @return int[] Re-indexed list of timestamps still inside the window.
	 */
	public static function filterWithinWindow(array $timestamps, int $now): array
	{
		$windowStart = $now - self::WINDOW_SECONDS;

		return array_values(array_filter(
			$timestamps,
			static fn($ts): bool => (int)$ts > $windowStart,
		));
	}

	/**
	 * Seconds until the window frees up, i.e. until the oldest counted call
	 * leaves the window. Always at least 1 for a full window.
	 *
	 * Pure helper, extracted for unit testing.
	 *
	 * @param int[] $timestamps Timestamps already filtered to the window.
	 * @param int $now
	 * @return int
	 */
	public static function retryAfter(array $timestamps, int $now): int
	{
		if (empty($timestamps))
		{
			return 0;
		}

		$oldest = min($timestamps);
		$retryAfter = ($oldest + self::WINDOW_SECONDS) - $now;

		return max(1, $retryAfter);
	}

	private function getCacheKey(int $userId): string
	{
		return 'bi_tools_rate_' . $userId;
	}

	private function getCacheDir(int $userId): string
	{
		return self::CACHE_DIR . substr(md5((string)$userId), 2, 2) . '/';
	}

	/**
	 * @return int[]
	 */
	private function read(string $key, string $cacheDir): array
	{
		$cache = Cache::createInstance();
		if ($cache->initCache(self::WINDOW_SECONDS, $key, $cacheDir))
		{
			return (array)$cache->getVars();
		}

		return [];
	}

	/**
	 * @param int[] $timestamps
	 */
	private function write(string $key, string $cacheDir, array $timestamps): void
	{
		$cache = Cache::createInstance();
		$cache->forceRewriting(true);
		if ($cache->startDataCache(self::WINDOW_SECONDS, $key, $cacheDir))
		{
			$cache->endDataCache($timestamps);
		}
	}
}

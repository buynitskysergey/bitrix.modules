<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\User;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;

class UserEventRateLimiter
{
	private const LIMIT = 120;
	private const WINDOW_SECONDS = 60;
	private const STORAGE_TTL_SECONDS = 120;
	private const FAILURE_DELAY_SECONDS = 5;
	private const STORAGE_KEY_PREFIX = 'vibecodeconnector.user-event-rate.';
	private const LOCK_KEY_PREFIX = 'vibecodeconnector:user-event-rate:';

	private readonly StorageInterface $storage;

	public function __construct(?StorageInterface $storage = null)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	public function reserve(string $pairingIss, int $now): int
	{
		$keyHash = hash('sha256', $pairingIss);
		$lockName = self::LOCK_KEY_PREFIX . $keyHash;
		if (!$this->acquireLock($lockName))
		{
			return self::FAILURE_DELAY_SECONDS;
		}

		try
		{
			$storageKey = self::STORAGE_KEY_PREFIX . $keyHash;
			try
			{
				$storedTimestamps = $this->storage->get($storageKey, []);
			}
			catch (\Throwable)
			{
				return self::FAILURE_DELAY_SECONDS;
			}

			if (!is_array($storedTimestamps))
			{
				return self::FAILURE_DELAY_SECONDS;
			}

			$timestamps = [];
			foreach ($storedTimestamps as $timestamp)
			{
				if (!is_int($timestamp))
				{
					return self::FAILURE_DELAY_SECONDS;
				}

				if ($timestamp > $now - self::WINDOW_SECONDS)
				{
					$timestamps[] = $timestamp;
				}
			}

			if (count($timestamps) >= self::LIMIT)
			{
				return max(1, $timestamps[0] + self::WINDOW_SECONDS - $now);
			}

			$timestamps[] = $now;
			try
			{
				if (!$this->storage->set($storageKey, $timestamps, self::STORAGE_TTL_SECONDS))
				{
					return self::FAILURE_DELAY_SECONDS;
				}
			}
			catch (\Throwable)
			{
				return self::FAILURE_DELAY_SECONDS;
			}

			return 0;
		}
		finally
		{
			$this->releaseLock($lockName);
		}
	}

	protected function acquireLock(string $lockName): bool
	{
		return Application::getConnection()->lock($lockName, 0);
	}

	protected function releaseLock(string $lockName): void
	{
		Application::getConnection()->unlock($lockName);
	}
}

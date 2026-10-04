<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Messenger;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message\UserListPullMessage;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Storage\VibecodeMessengerMessageTable;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventsAvailability;

class UserListPullQueueGuard
{
	private const CAPACITY_LIMIT = 10000;
	private const STORAGE_TTL_SECONDS = 86400;
	private const STORAGE_KEY_PREFIX = 'vibecodeconnector.user-list-pull-queue.';
	private const LOCK_KEY = 'vibecodeconnector:user-list-pull-queue';
	private const MODULE_ID = 'vibecodeconnector';
	private const VERSION_OPTION_PREFIX = 'ulv_';

	private readonly StorageInterface $storage;

	public function __construct(
		?StorageInterface $storage = null,
		private readonly UserEventsAvailability $userEventsAvailability = new UserEventsAvailability(),
		private readonly ModuleOptions $options = new ModuleOptions(),
	)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	public function tryEnqueue(UserListPullMessage $message): bool
	{
		if (!$this->userEventsAvailability->isEnabled())
		{
			return true;
		}

		if (!$this->acquireLock(self::LOCK_KEY))
		{
			return false;
		}

		try
		{
			$storageKey = $this->getStorageKey($message);
			$successfulVersion = $this->getSuccessfulVersion($message);
			if (
				$message->version !== null
				&& $successfulVersion !== null
				&& $message->version <= $successfulVersion
			)
			{
				return true;
			}

			$marker = $this->readMarker($storageKey);
			if ($marker === false)
			{
				return false;
			}

			if ($marker !== null)
			{
				if (($marker['active'] ?? null) !== true)
				{
					return false;
				}
				$currentVersion = is_int($marker['version'] ?? null) ? $marker['version'] : null;
				$pendingVersion = is_int($marker['pendingVersion'] ?? null) ? $marker['pendingVersion'] : null;
				if (
					$message->version !== null
					&& ($currentVersion === null || $message->version > $currentVersion)
					&& ($pendingVersion === null || $message->version > $pendingVersion)
				)
				{
					$marker['pendingVersion'] = $message->version;
					if (!$this->saveMarker($storageKey, $marker))
					{
						return false;
					}
				}

				return true;
			}

			try
			{
				if ($this->countQueueRows() >= self::CAPACITY_LIMIT)
				{
					return false;
				}
			}
			catch (\Throwable)
			{
				return false;
			}

			$this->sendMessage($message);
			if (!$this->saveMarker($storageKey, $this->createMarker($message)))
			{
				return false;
			}

			return true;
		}
		finally
		{
			$this->releaseLock(self::LOCK_KEY);
		}
	}

	public function refresh(UserListPullMessage $message): bool
	{
		if (!$this->acquireLock(self::LOCK_KEY))
		{
			return false;
		}

		try
		{
			$storageKey = $this->getStorageKey($message);
			$marker = $this->readMarker($storageKey);
			if ($marker === false)
			{
				return false;
			}
			if ($marker !== null && !$this->isMarkerForMessage($marker, $message))
			{
				return true;
			}

			return $this->saveMarker($storageKey, $marker ?? $this->createMarker($message));
		}
		finally
		{
			$this->releaseLock(self::LOCK_KEY);
		}
	}

	public function complete(UserListPullMessage $message): void
	{
		if (!$this->acquireLock(self::LOCK_KEY))
		{
			throw new \RuntimeException('Unable to finalize user list pull');
		}

		try
		{
			$storageKey = $this->getStorageKey($message);
			$marker = $this->readMarker($storageKey);
			if ($marker === false)
			{
				throw new \RuntimeException('Unable to read user list pull marker');
			}

			$this->saveSuccessfulVersion($message);
			if ($marker !== null && !$this->isMarkerForMessage($marker, $message))
			{
				return;
			}

			$successfulVersion = $this->getSuccessfulVersion($message);
			$pendingVersion = is_int($marker['pendingVersion'] ?? null) ? $marker['pendingVersion'] : null;
			if (
				$pendingVersion === null
				|| ($successfulVersion !== null && $pendingVersion <= $successfulVersion)
			)
			{
				$this->deleteMarker($storageKey);

				return;
			}

			$nextMessage = new UserListPullMessage(
				pairingIss: $message->pairingIss,
				endpointUrl: $message->endpointUrl,
				version: $pendingVersion,
			);
			$this->sendMessage($nextMessage);
			if (!$this->saveMarker($storageKey, $this->createMarker($nextMessage)))
			{
				throw new \RuntimeException('Unable to update user list pull marker');
			}
		}
		finally
		{
			$this->releaseLock(self::LOCK_KEY);
		}
	}

	public function release(UserListPullMessage $message): void
	{
		$storageKey = $this->getStorageKey($message);
		if (!$this->acquireLock(self::LOCK_KEY))
		{
			throw new \RuntimeException('Unable to finalize user list pull');
		}

		try
		{
			$marker = $this->readMarker($storageKey);
			if ($marker === false)
			{
				throw new \RuntimeException('Unable to read user list pull marker');
			}
			if ($marker === null || !$this->isMarkerForMessage($marker, $message))
			{
				return;
			}

			$pendingVersion = is_int($marker['pendingVersion'] ?? null) ? $marker['pendingVersion'] : null;
			if ($pendingVersion === null)
			{
				$this->deleteMarker($storageKey);

				return;
			}

			$nextMessage = new UserListPullMessage(
				pairingIss: $message->pairingIss,
				endpointUrl: $message->endpointUrl,
				version: $pendingVersion,
			);
			$this->sendMessage($nextMessage);
			if (!$this->saveMarker($storageKey, $this->createMarker($nextMessage)))
			{
				throw new \RuntimeException('Unable to update user list pull marker');
			}
		}
		finally
		{
			$this->releaseLock(self::LOCK_KEY);
		}
	}

	public function discard(UserListPullMessage $message): void
	{
		if (!$this->acquireLock(self::LOCK_KEY))
		{
			throw new \RuntimeException('Unable to discard user list pull');
		}

		try
		{
			$storageKey = $this->getStorageKey($message);
			$marker = $this->readMarker($storageKey);
			if ($marker === false)
			{
				throw new \RuntimeException('Unable to read user list pull marker');
			}
			if ($marker === null || !$this->isMarkerForMessage($marker, $message))
			{
				return;
			}

			$this->deleteMarker($storageKey);
		}
		finally
		{
			$this->releaseLock(self::LOCK_KEY);
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

	protected function countQueueRows(): int
	{
		return (int)VibecodeMessengerMessageTable::query()
			->where('QUEUE_ID', QueueId::UserListPull->value)
			->queryCountTotal()
		;
	}

	protected function sendMessage(UserListPullMessage $message): void
	{
		$message->send(QueueId::UserListPull->value);
	}

	private function readMarker(string $storageKey): array|false|null
	{
		try
		{
			$marker = $this->storage->get($storageKey);
		}
		catch (\Throwable)
		{
			return false;
		}

		if ($marker === null)
		{
			return null;
		}

		return is_array($marker) ? $marker : false;
	}

	private function saveMarker(string $storageKey, array $marker): bool
	{
		try
		{
			return $this->storage->set($storageKey, $marker, self::STORAGE_TTL_SECONDS);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function createMarker(UserListPullMessage $message): array
	{
		return [
			'active' => true,
			'generationId' => $message->generationId,
			'version' => $message->version,
			'pendingVersion' => null,
		];
	}

	private function isMarkerForMessage(array $marker, UserListPullMessage $message): bool
	{
		$markerGenerationId = is_string($marker['generationId'] ?? null) ? $marker['generationId'] : null;

		return $markerGenerationId === $message->generationId;
	}

	private function getStorageKey(UserListPullMessage $message): string
	{
		return self::STORAGE_KEY_PREFIX . hash(
			'sha256',
			$message->pairingIss . "\0" . $message->endpointUrl,
		);
	}

	/**
	 * The successful version never goes down: a lower version means the queue would pull a user list
	 * older than the one already applied. The version is written by both the web request and the
	 * queue consumer, so the request memory of the options component covers only its own process,
	 * while the platform option cache of a neighbouring one may be stale. A process reading a stale
	 * lower value would write it over the newer one and lower the version, so the read goes straight
	 * to the options table. The write stays with the options component.
	 */
	private function getSuccessfulVersion(UserListPullMessage $message): ?int
	{
		$value = $this->getFreshOptionValue($this->getVersionOptionName($message));

		return is_string($value) && preg_match('/^-?\d+$/D', $value) === 1 ? (int)$value : null;
	}

	/**
	 * The option is global, so a single row of b_option answers it. The platform lowercases the
	 * option name on writing, and this read has to match it.
	 */
	private function getFreshOptionValue(string $name): ?string
	{
		$sqlExpression = new SqlExpression(
			'SELECT ?# FROM ?# WHERE ?# = ?s AND ?# = ?s',
			'VALUE',
			'b_option',
			'MODULE_ID',
			self::MODULE_ID,
			'NAME',
			mb_strtolower($name),
		);
		$value = Application::getConnection()->queryScalar($sqlExpression->compile());

		return is_string($value) ? $value : null;
	}

	private function saveSuccessfulVersion(UserListPullMessage $message): void
	{
		if ($message->version === null)
		{
			return;
		}

		$currentVersion = $this->getSuccessfulVersion($message);
		if ($currentVersion === null || $message->version > $currentVersion)
		{
			$this->options->set($this->getVersionOptionName($message), (string)$message->version);
		}
	}

	private function getVersionOptionName(UserListPullMessage $message): string
	{
		return self::VERSION_OPTION_PREFIX . substr(hash(
			'sha256',
			$message->pairingIss . "\0" . $message->endpointUrl,
		), 0, 40);
	}

	private function deleteMarker(string $storageKey): void
	{
		try
		{
			$deleted = $this->storage->delete($storageKey);
		}
		catch (\Throwable $exception)
		{
			throw new \RuntimeException('Unable to delete user list pull marker', previous: $exception);
		}
		if (!$deleted)
		{
			throw new \RuntimeException('Unable to delete user list pull marker');
		}
	}
}

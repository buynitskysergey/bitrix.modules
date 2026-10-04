<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Messenger;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message\UserEventMessage;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Storage\VibecodeMessengerMessageTable;

class UserEventQueueCapacityGuard
{
	private const CAPACITY_LIMIT = 350000;
	private const RECONCILE_INTERVAL_SECONDS = 60;
	private const STORAGE_TTL_SECONDS = 86400;
	private const LOCK_WAIT_TIMEOUT_SECONDS = 1;
	private const STATE_KEY = 'vibecodeconnector.user-event-capacity';
	private const LOCK_KEY = 'vibecodeconnector:user-event-capacity';

	private readonly StorageInterface $storage;

	public function __construct(
		?StorageInterface $storage = null,
	) {
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	public function trySend(UserEventMessage $message): bool
	{
		if (!$this->acquireLock(self::LOCK_KEY, self::LOCK_WAIT_TIMEOUT_SECONDS))
		{
			return false;
		}

		try
		{
			$now = $this->getCurrentTime();
			$state = $this->readState();
			if ($state === null)
			{
				return false;
			}

			if (
				!isset($state['upperBound'], $state['reconciledAt'])
				|| !is_int($state['upperBound'])
				|| !is_int($state['reconciledAt'])
				|| $state['reconciledAt'] <= $now - self::RECONCILE_INTERVAL_SECONDS
			)
			{
				$state['upperBound'] = $this->countQueueRows();
				$state['reconciledAt'] = $now;
			}

			if ($state['upperBound'] >= self::CAPACITY_LIMIT)
			{
				return false;
			}

			$state['upperBound']++;
			if (!$this->saveState($state))
			{
				return false;
			}

			$this->sendMessage($message);

			return true;
		}
		finally
		{
			$this->releaseLock(self::LOCK_KEY);
		}
	}

	protected function acquireLock(string $lockName, int $timeout): bool
	{
		return Application::getConnection()->lock($lockName, $timeout);
	}

	protected function releaseLock(string $lockName): void
	{
		Application::getConnection()->unlock($lockName);
	}

	protected function getCurrentTime(): int
	{
		return time();
	}

	protected function countQueueRows(): int
	{
		return (int)VibecodeMessengerMessageTable::query()
			->where('QUEUE_ID', QueueId::UserEvent->value)
			->queryCountTotal()
		;
	}

	protected function sendMessage(UserEventMessage $message): void
	{
		$message->send(QueueId::UserEvent->value);
	}

	private function readState(): ?array
	{
		try
		{
			$state = $this->storage->get(self::STATE_KEY, []);
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($state) ? $state : null;
	}

	private function saveState(array $state): bool
	{
		try
		{
			return $this->storage->set(self::STATE_KEY, $state, self::STORAGE_TTL_SECONDS);
		}
		catch (\Throwable)
		{
			return false;
		}
	}
}

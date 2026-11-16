<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Collection;

use Bitrix\Main\Application;

/**
 * Serialises the long transitions of one knowledge base: the return from the recycle bin and the
 * final purge, by hand or by the cleanup agent, must never run at once on the same base.
 */
class CollectionLockService
{
	private const LOCK_PREFIX = 'note_collection_';

	/**
	 * Zero timeout by default: the transitions exclude each other, and the losing side has nothing to
	 * wait for - the agent skips the base until its next run, a person is told to try again.
	 */
	public function acquire(int $collectionId, int $timeoutSeconds = 0): bool
	{
		return Application::getConnection()->lock($this->buildKey($collectionId), $timeoutSeconds);
	}

	public function release(int $collectionId): void
	{
		Application::getConnection()->unlock($this->buildKey($collectionId));
	}

	private function buildKey(int $collectionId): string
	{
		return self::LOCK_PREFIX . $collectionId;
	}
}

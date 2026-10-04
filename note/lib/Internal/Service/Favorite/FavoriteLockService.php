<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Favorite;

use Bitrix\Main\Application;

/**
 * [P3.T3] Serialises the two writes that share one invariant: a positive subscription exists only while
 * the object sits in the caller's favorites. Each side reads the state the other one writes, and a plain
 * SELECT is no barrier here - under REPEATABLE READ it does not even see a row committed since the
 * transaction began, so the check can pass on a favorite that is already gone.
 *
 * The lock is per user and object: two people never wait for each other, and neither do two objects of
 * the same person. Only the same star pressed from two places at once serialises, which is the point.
 */
class FavoriteLockService
{
	private const LOCK_PREFIX = 'note_favorite_';

	/**
	 * Competing writes on one object by one user are a matter of milliseconds. Waiting longer than this
	 * means the other side is stuck rather than busy - and going ahead unserialised is exactly what
	 * leaves a positive subscription behind on an object no longer in the list.
	 */
	public const LOCK_TIMEOUT = 5;

	public function acquire(
		int $userId,
		string $entityType,
		int $entityId,
		int $timeoutSeconds = self::LOCK_TIMEOUT,
	): bool
	{
		return Application::getConnection()->lock(
			$this->buildKey($userId, $entityType, $entityId),
			$timeoutSeconds,
		);
	}

	public function release(int $userId, string $entityType, int $entityId): void
	{
		Application::getConnection()->unlock($this->buildKey($userId, $entityType, $entityId));
	}

	private function buildKey(int $userId, string $entityType, int $entityId): string
	{
		return self::LOCK_PREFIX . $userId . '_' . $entityType . '_' . $entityId;
	}
}

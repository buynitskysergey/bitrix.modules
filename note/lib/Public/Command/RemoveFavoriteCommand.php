<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Model\SubscriptionTable;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
use Bitrix\Note\Internal\Repository\SubscriptionRepository;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Favorite\FavoriteLockService;

/**
 * [P3.T1 / API-02] Takes one object out of the caller's favorites together with the positive
 * notification subscription on it, and reports what was taken away so a caller can put it back.
 *
 * ACL is deliberately not checked anywhere on this path: a user must be able to clean their own list
 * even after losing access to the object.
 */
final class RemoveFavoriteCommand extends AbstractCommand
{
	private const POSITIVE_MODES = [
		SubscriptionTable::MODE_SELF,
		SubscriptionTable::MODE_SUBTREE,
		SubscriptionTable::MODE_ALL,
	];

	public function __construct(
		private readonly int $userId,
		private readonly string $entityType,
		private readonly int $entityId,
		private readonly FavoriteRepository $repository = new FavoriteRepository(),
		private readonly SubscriptionRepository $subscriptionRepository = new SubscriptionRepository(),
		private readonly PushNotificationService $pushService = new PushNotificationService(),
		private readonly FavoriteLockService $lockService = new FavoriteLockService(),
	) {}

	/**
	 * The other half of the invariant SetSubscriptionCommand holds: without the same lock, a subscribe
	 * that has already checked the star can commit after the star is gone, and the object keeps
	 * notifying with no row left to switch it off from.
	 */
	protected function execute(): Result
	{
		if (!$this->lockService->acquire($this->userId, $this->entityType, $this->entityId))
		{
			throw new \RuntimeException('Failed to acquire the favorite lock of the removed object');
		}

		try
		{
			return $this->write();
		}
		finally
		{
			$this->lockService->release($this->userId, $this->entityType, $this->entityId);
		}
	}

	private function write(): Result
	{
		$row = $this->repository->findRow($this->userId, $this->entityType, $this->entityId);
		if ($row === null)
		{
			return $this->createResult(['success' => true, 'removed' => null]);
		}

		$ordinal = $this->resolveOrdinal($row['id']);
		$removedMode = null;

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			// The mode is read for the report only; whether the row goes is decided by the DELETE
			// filter itself, so a mode switched concurrently cannot leave a positive subscription on an
			// object that is no longer starred.
			// Only positive modes go: a muted row must survive, otherwise unstarring a muted object
			// would resume notifications on it and there would be nowhere left to mute it again.
			// MutePruner is intentionally not called here - an orphaned mute is what a restore comes back to.
			$removedMode = $this->resolvePositiveMode();
			$subscriptionRemoved = $this->subscriptionRepository->removeByModes(
				$this->userId,
				$this->entityType,
				$this->entityId,
				self::POSITIVE_MODES,
			);
			if (!$subscriptionRemoved)
			{
				$removedMode = null;
			}

			$this->repository->remove($this->userId, $this->entityType, $this->entityId);
			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}

		$this->emitRemove($row['id'], $removedMode !== null);

		return $this->createResult([
			'success' => true,
			'removed' => [
				'position' => $ordinal,
				'notifyMode' => $removedMode,
			],
		]);
	}

	/**
	 * Ordinal of the row in the user's list (1 is the topmost one) - the form API-01 accepts as an
	 * insertion point, so a restore can hand it straight back.
	 */
	private function resolveOrdinal(int $favoriteId): int
	{
		$ordinal = 1;
		foreach ($this->repository->getPositions($this->userId) as $index => $candidate)
		{
			if ((int)$candidate['id'] === $favoriteId)
			{
				$ordinal = $index + 1;
				break;
			}
		}

		return $ordinal;
	}

	private function resolvePositiveMode(): ?string
	{
		$state = $this->subscriptionRepository->getUserState($this->userId, $this->entityType, $this->entityId);
		$mode = $state['mode'] ?? null;

		return in_array($mode, self::POSITIVE_MODES, true) ? (string)$mode : null;
	}

	/**
	 * [EVENT-01 / EVENT-02] The only place in the feature where one command publishes two events: a
	 * bell opened in another tab has no other way to learn the subscription went with the star.
	 */
	private function emitRemove(int $favoriteId, bool $subscriptionRemoved): void
	{
		$favoritePayload = [
			'id' => $favoriteId,
			'entityType' => $this->entityType,
			'entityId' => $this->entityId,
		];
		$subscriptionPayload = [
			'scope' => $this->entityType,
			'entityId' => $this->entityId,
		];

		$pushService = $this->pushService;
		$userId = $this->userId;
		$pushService->dispatchAfterCommit(
			static function () use ($pushService, $userId, $favoritePayload, $subscriptionPayload, $subscriptionRemoved): void {
				$pushService->sendToUserChannel($userId, 'favoriteRemove', $favoritePayload);
				if ($subscriptionRemoved)
				{
					$pushService->sendToUserChannel($userId, 'subscriptionRemove', $subscriptionPayload);
				}
			}
		);
	}

	private function createResult(array $data = []): Result
	{
		$result = new Result();
		if (!empty($data))
		{
			$result->setData($data);
		}

		return $result;
	}
}

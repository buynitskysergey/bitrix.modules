<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Model\SubscriptionTable;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
use Bitrix\Note\Internal\Repository\SubscriptionRepository;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Favorite\FavoriteLockService;

/**
 * [P6.T3 / API-07] Subscribe / change scope. Idempotent upsert on
 * UNIQUE(USER_ID, SCOPE, ENTITY_ID) — scope/mode validity and the "view on
 * target" permission are the controller's responsibility (per MODULE.md,
 * commands do not re-check ACL); this command only persists the row.
 *
 * [P3.T3] Positive modes additionally require an existing favorite row on the same object: the
 * subscription is a property of a favorite, not a standalone entity.
 */
class SetSubscriptionCommand extends AbstractCommand
{
	public const ERROR_FAVORITE_REQUIRED = 'FAVORITE_REQUIRED';

	public function __construct(
		private readonly int $userId,
		private readonly string $scope,
		private readonly int $entityId,
		private readonly string $mode,
		private readonly SubscriptionRepository $repository = new SubscriptionRepository(),
		private readonly FavoriteRepository $favoriteRepository = new FavoriteRepository(),
		private readonly PushNotificationService $pushService = new PushNotificationService(),
		private readonly FavoriteLockService $lockService = new FavoriteLockService(),
	) {}

	protected function execute(): Result
	{
		// A mute takes part in no invariant - it is a negative override that outlives the star itself,
		// so it writes without waiting for anyone.
		$guarded = $this->mode !== SubscriptionTable::MODE_MUTED;
		if ($guarded && !$this->lockService->acquire($this->userId, $this->scope, $this->entityId))
		{
			throw new \RuntimeException('Failed to acquire the favorite lock of the subscription target');
		}

		try
		{
			return $this->write($guarded);
		}
		finally
		{
			if ($guarded)
			{
				$this->lockService->release($this->userId, $this->scope, $this->entityId);
			}
		}
	}

	/**
	 * @param bool $guarded whether the caller holds the favorite lock of the target
	 */
	private function write(bool $guarded): Result
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			// Refusal, not an implicit write: the user's list must never grow as a side effect of another
			// action. Muting is the exception - it is a negative override and needs no favorite row.
			// The transaction is no barrier by itself: this read takes no lock, and under REPEATABLE READ
			// it answers from the snapshot, so a concurrent unstar committed meanwhile stays invisible and
			// the upsert below would leave a positive subscription with no favorite row behind it. What
			// makes the pair atomic is the lock taken in execute() and held by the unstar as well.
			if (
				$guarded
				&& $this->favoriteRepository->findRow($this->userId, $this->scope, $this->entityId) === null
			)
			{
				// Committed, not rolled back: nothing has been written yet, and a nested rollback rolls
				// back to the savepoint AND throws, which would turn a refusal into a failure and unbalance
				// the caller's transaction.
				$connection->commitTransaction();

				return $this->createResult([
					'success' => false,
					'errorCode' => self::ERROR_FAVORITE_REQUIRED,
				]);
			}

			$this->repository->upsert($this->userId, $this->scope, $this->entityId, $this->mode, new DateTime());
			// Subscribing to the whole collection makes per-document self/subtree rows redundant — drop
			// them so every document reads uniformly as "covered by the knowledge base" (mutes kept).
			if ($this->scope === SubscriptionTable::SCOPE_COLLECTION && $this->mode === SubscriptionTable::MODE_ALL)
			{
				$this->repository->removeRedundantDocumentSubscriptions($this->userId, $this->entityId);
			}
			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}

		$this->emitSet();

		return $this->createResult(['success' => true]);
	}

	/**
	 * [EVENT-02] subscriptionSet into the personal channel. The initiator is not excluded: the bell
	 * of the same document open in another tab has no other way to converge.
	 */
	private function emitSet(): void
	{
		$payload = [
			'scope' => $this->scope,
			'entityId' => $this->entityId,
			'mode' => $this->mode,
		];

		$pushService = $this->pushService;
		$userId = $this->userId;
		$pushService->dispatchAfterCommit(static function () use ($pushService, $userId, $payload): void {
			$pushService->sendToUserChannel($userId, 'subscriptionSet', $payload);
		});
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

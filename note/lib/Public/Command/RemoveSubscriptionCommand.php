<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Repository\SubscriptionRepository;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Subscription\MutePruner;

/**
 * [P6.T3 / API-08] Unsubscribe — removes the caller's own subscription row.
 * Idempotent: a missing row is not an error. No ACL check — a user must always
 * be able to remove their own subscription, even after losing view access to
 * the target (per SDD: "потеря доступа строку не удаляет", the row can still
 * go away explicitly).
 */
class RemoveSubscriptionCommand extends AbstractCommand
{
	public function __construct(
		private readonly int $userId,
		private readonly string $scope,
		private readonly int $entityId,
		private readonly SubscriptionRepository $repository = new SubscriptionRepository(),
		private readonly MutePruner $mutePruner = new MutePruner(),
		private readonly PushNotificationService $pushService = new PushNotificationService(),
	) {}

	protected function execute(): Result
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$this->repository->remove($this->userId, $this->scope, $this->entityId);
			// Removing a subtree/collection subscription can strip the coverage a mute was suppressing;
			// prune the user's now-orphaned mutes so they don't silently re-suppress on re-subscribe.
			$this->mutePruner->pruneOrphaned($this->userId);
			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}

		$this->emitRemove();

		return $this->createResult(['success' => true]);
	}

	/**
	 * [EVENT-02] subscriptionRemove into the personal channel. The initiator is not excluded: the same
	 * bell may be open in another tab and in the mobile app.
	 */
	private function emitRemove(): void
	{
		$payload = [
			'scope' => $this->scope,
			'entityId' => $this->entityId,
		];

		$pushService = $this->pushService;
		$userId = $this->userId;
		$pushService->dispatchAfterCommit(static function () use ($pushService, $userId, $payload): void {
			$pushService->sendToUserChannel($userId, 'subscriptionRemove', $payload);
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

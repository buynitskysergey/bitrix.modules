<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Model\SubscriptionTable;
use Bitrix\Note\Internal\Repository\SubscriptionRepository;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;

/**
 * Subscribe / change scope. Idempotent upsert on
 * UNIQUE(USER_ID, SCOPE, ENTITY_ID) — scope/mode validity and the "view on
 * target" permission are the controller's responsibility (per MODULE.md,
 * commands do not re-check ACL); this command only persists the row.
 *
 * A subscription is a row of its own: it needs no favorite on the same object,
 * and unstarring that object does not take it away.
 */
class SetSubscriptionCommand extends AbstractCommand
{
	public function __construct(
		private readonly int $userId,
		private readonly string $scope,
		private readonly int $entityId,
		private readonly string $mode,
		private readonly SubscriptionRepository $repository = new SubscriptionRepository(),
		private readonly PushNotificationService $pushService = new PushNotificationService(),
	) {}

	/**
	 * The transaction covers the two rows this command may touch (the subscription itself and the
	 * per-document rows it makes redundant), so a reader never sees one without the other.
	 */
	protected function execute(): Result
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
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
	 * subscriptionSet into the personal channel. The initiator is not excluded: the bell
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

<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Collection\CollectionService;

class UpdateCollectionCommand extends AbstractCommand
{
	private readonly int $id;
	private readonly string $name;
	private readonly int $userId;
	private readonly CollectionService $collectionService;
	private readonly PushNotificationService $pushService;
	private readonly bool $notifyInitiator;

	public function __construct(
		int $id,
		string $name,
		int $userId,
		?CollectionService $collectionService = null,
		?PushNotificationService $pushService = null,
		// REST writes are out-of-band: notify the initiator's own sessions instead of skipping them.
		bool $notifyInitiator = false,
	)
	{
		$this->id = $id;
		$this->name = $name;
		$this->userId = $userId;
		$this->collectionService = $collectionService ?? new CollectionService();
		$this->pushService = $pushService ?? new PushNotificationService();
		$this->notifyInitiator = $notifyInitiator;
	}

	protected function execute(): Result
	{
		// Renaming is a write to the base itself, so the state of the base decides it: an archived one
		// is read-only. Hiding the field is not enough - the id alone is enough to call the endpoint.
		CollectionAccessService::assertCollectionAcceptsWrites($this->id);

		$collection = $this->collectionService->update($this->id, $this->name, $this->userId);

		if ($collection !== null)
		{
			$this->emitCollectionUpdate((int)$collection->getId(), (string)$collection->getName());
		}

		$result = new Result();
		$result->setData(['collection' => $collection]);

		return $result;
	}

	private function emitCollectionUpdate(int $collectionId, string $name): void
	{
		$initiatorUserId = $this->notifyInitiator ? null : $this->userId;
		$pushService = $this->pushService;

		$pushService->dispatchAfterCommit(static function () use ($pushService, $collectionId, $name, $initiatorUserId): void {
			$pushService->sendToCollection(
				$collectionId,
				'collectionUpdate',
				[
					'collectionId' => $collectionId,
					'name' => $name,
				],
				$initiatorUserId,
			);
		});
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Collection\CollectionService;

/**
 * The create_collection analytics event is deliberately NOT sent from here. Every caller knows the
 * composition of the fresh collection better than the command does: the web permission dialog counts
 * the members the user picked before the collection existed, the importer names the source tool. A
 * send from here would either double-count the web path or replace those numbers with a guess.
 */
class CreateCollectionCommand extends AbstractCommand
{
	private readonly string $name;
	private readonly int $createdBy;
	private readonly int $position;
	private readonly string|int|null $policyLevel;
	private readonly ?array $permissions;
	private readonly CollectionService $collectionService;
	private readonly PushNotificationService $pushService;

	public function __construct(
		string $name,
		int $createdBy,
		int $position = 0,
		string|int|null $policyLevel = null,
		?array $permissions = null,
		?CollectionService $collectionService = null,
		?PushNotificationService $pushService = null,
	)
	{
		$this->name = $name;
		$this->createdBy = $createdBy;
		$this->position = $position;
		$this->policyLevel = $policyLevel;
		$this->permissions = $permissions;
		$this->collectionService = $collectionService ?? new CollectionService();
		$this->pushService = $pushService ?? new PushNotificationService();
	}

	protected function execute(): Result
	{
		$collection = $this->collectionService->create(
			$this->name,
			$this->createdBy,
			$this->position,
			$this->policyLevel,
			$this->permissions,
		);

		$this->emitCollectionCreate((int)$collection->getId());

		$result = new Result();
		$result->setData(['collection' => $collection]);

		return $result;
	}

	private function emitCollectionCreate(int $collectionId): void
	{
		$pushService = $this->pushService;

		$pushService->dispatchAfterCommit(static function () use ($pushService, $collectionId): void {
			$pushService->sendGlobal(
				'collectionCreate',
				['collectionId' => $collectionId],
			);
		});
	}
}

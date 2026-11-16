<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Collection\CollectionPositionService;

final class MoveCollectionCommand extends AbstractCommand
{
	private readonly int $id;
	private readonly ?int $position;
	private readonly ?int $targetId;
	private readonly string $placement;
	private readonly int $userId;
	private readonly CollectionPositionService $positionService;
	private readonly PushNotificationService $pushService;

	public function __construct(
		int $id,
		?int $position,
		int $userId,
		?CollectionPositionService $positionService = null,
		?PushNotificationService $pushService = null,
		?int $targetId = null,
		string $placement = 'before',
	)
	{
		$this->id = $id;
		$this->position = $position;
		$this->targetId = $targetId;
		$this->placement = $placement;
		$this->userId = $userId;
		$this->positionService = $positionService ?? new CollectionPositionService();
		$this->pushService = $pushService ?? new PushNotificationService();
	}

	// The sidebar names the neighbour the base must stand next to: the list it draws holds only the
	// bases open to the person, while the order is one for the whole portal, so a place counted in
	// the panel would point at someone else's gap.
	public static function nextTo(
		int $id,
		int $targetId,
		string $placement,
		int $userId,
		?CollectionPositionService $positionService = null,
		?PushNotificationService $pushService = null,
	): self
	{
		return new self($id, null, $userId, $positionService, $pushService, $targetId, $placement);
	}

	protected function execute(): Result
	{
		// The order of the list is a property of the base, so the state of the base decides it:
		// an archived one is read-only and stays out of the reorder.
		CollectionAccessService::assertCollectionAcceptsWrites($this->id);

		$result = $this->targetId === null
			? $this->positionService->move($this->id, $this->position, $this->userId)
			: $this->positionService->moveNextTo($this->id, $this->targetId, $this->placement, $this->userId)
		;
		if (!$result->isSuccess())
		{
			throw new SystemException(
				implode(', ', $result->getErrorMessages()) ?: 'Unable to move collection.'
			);
		}

		$affected = $result->getData()['affectedPositions'] ?? [];
		if (is_array($affected) && $affected !== [])
		{
			$this->emitCollectionMove($affected);
		}

		return $result;
	}

	private function emitCollectionMove(array $affectedPositions): void
	{
		$initiatorUserId = $this->userId;
		$pushService = $this->pushService;

		$payload = count($affectedPositions) > PushNotificationService::REALTIME_BATCH_THRESHOLD
			? ['requestRefetch' => true]
			: ['affectedPositions' => array_values($affectedPositions)];

		$pushService->dispatchAfterCommit(static function () use ($pushService, $payload, $initiatorUserId): void {
			$pushService->sendGlobal('collectionMove', $payload, $initiatorUserId);
		});
	}
}

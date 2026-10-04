<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Favorite\FavoritePositionService;

/**
 * [P3.T1 / API-03] Reorders one row of the caller's own favorites. $position is the ordinal of the
 * target insertion point (1 is the topmost row).
 *
 * Order is the only thing that changes, and FavoritePositionService::move() is already atomic on its
 * own, so this command opens no transaction of its own. No ACL check: this is an operation over a
 * personal list, not over the object.
 */
final class MoveFavoriteCommand extends AbstractCommand
{
	public function __construct(
		private readonly int $userId,
		private readonly string $entityType,
		private readonly int $entityId,
		private readonly ?int $position = null,
		private readonly FavoriteRepository $repository = new FavoriteRepository(),
		private readonly FavoritePositionService $positionService = new FavoritePositionService(),
		private readonly PushNotificationService $pushService = new PushNotificationService(),
	) {}

	protected function execute(): Result
	{
		$row = $this->repository->findRow($this->userId, $this->entityType, $this->entityId);
		if ($row === null)
		{
			// Nothing to reorder: the row may have been dropped by a parallel removal. Idempotent
			// success with an empty position, so a client mid-drag does not surface an error.
			return $this->createResult([
				'success' => true,
				'position' => null,
				'affectedPositions' => [],
			]);
		}

		$moveResult = $this->positionService->move($this->userId, $row['id'], $this->position);
		if (!$moveResult->isSuccess())
		{
			throw new SystemException(
				implode(', ', $moveResult->getErrorMessages()) ?: 'Unable to move the favorite row.'
			);
		}

		$data = $moveResult->getData();
		if (!isset($data['position']))
		{
			// The same window as above, one step later: the row went between the read and the move. The
			// position read before is not where anything stands now, and an event carrying it would put the
			// row back in every other session of this user.
			return $this->createResult([
				'success' => true,
				'position' => null,
				'affectedPositions' => [],
			]);
		}

		$position = (int)$data['position'];
		$affectedPositions = array_values($data['affectedPositions'] ?? []);

		$this->emitMove($row['id'], $position, $affectedPositions);

		return $this->createResult([
			'success' => true,
			'position' => $position,
			'affectedPositions' => $affectedPositions,
		]);
	}

	/**
	 * [EVENT-01] favoriteMove. Above REALTIME_BATCH_THRESHOLD the payload degrades to a refetch
	 * request instead of carrying the renumbered list - same rule as the other list events.
	 *
	 * @param array<int, array{id: int, position: int}> $affectedPositions
	 */
	private function emitMove(int $favoriteId, int $position, array $affectedPositions): void
	{
		$payload = [
			'id' => $favoriteId,
			'entityType' => $this->entityType,
			'entityId' => $this->entityId,
		];
		if (count($affectedPositions) > PushNotificationService::REALTIME_BATCH_THRESHOLD)
		{
			$payload['requestRefetch'] = true;
		}
		else
		{
			$payload['position'] = $position;
			$payload['affectedPositions'] = $affectedPositions;
		}

		$pushService = $this->pushService;
		$userId = $this->userId;
		$pushService->dispatchAfterCommit(static function () use ($pushService, $userId, $payload): void {
			$pushService->sendToUserChannel($userId, 'favoriteMove', $payload);
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

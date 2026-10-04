<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Favorite\FavoritePositionService;
use Bitrix\Note\Public\Provider\FavoriteProvider;

/**
 * [P3.T1 / API-01] Puts one object into the caller's favorites. $position is the ordinal of the
 * insertion point (1 is the topmost row); without it the row goes on top of the list.
 *
 * ACL is the controller's responsibility (per MODULE.md, commands do not re-check it), and so is
 * whether the target may be starred at all - the command never looks at the object itself.
 */
final class AddFavoriteCommand extends AbstractCommand
{
	public function __construct(
		private readonly int $userId,
		private readonly string $entityType,
		private readonly int $entityId,
		private readonly ?int $position = null,
		private readonly FavoriteRepository $repository = new FavoriteRepository(),
		private readonly FavoritePositionService $positionService = new FavoritePositionService(),
		private readonly PushNotificationService $pushService = new PushNotificationService(),
		private readonly FavoriteProvider $provider = new FavoriteProvider(),
	) {}

	protected function execute(): Result
	{
		$existing = $this->repository->findRow($this->userId, $this->entityType, $this->entityId);
		if ($existing !== null)
		{
			$item = $this->buildItem();
			// Idempotent repeat: the row keeps its place, $position is ignored. The event still goes
			// out - it describes the converged state and EVENT-01 receivers apply it idempotently.
			$this->emitAdd($existing['id'], $existing['position'], [], $item);

			return $this->createResult([
				'success' => true,
				'id' => $existing['id'],
				'position' => $existing['position'],
				'affectedPositions' => [],
				'item' => $item,
			]);
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$allocation = $this->positionService->allocate($this->userId, $this->position);
			if (!$allocation->isSuccess())
			{
				throw new SystemException(
					implode(', ', $allocation->getErrorMessages()) ?: 'Unable to allocate a favorite position.'
				);
			}

			$allocated = $allocation->getData();
			$affectedPositions = array_values($allocated['affectedPositions'] ?? []);
			$this->repository->add(
				$this->userId,
				$this->entityType,
				$this->entityId,
				(int)($allocated['position'] ?? 0),
				new DateTime(),
			);

			// Re-read instead of trusting the allocation: a concurrent add of the same object wins the
			// MERGE, and then the position that actually stands is the one the other request wrote.
			$row = $this->repository->findRow($this->userId, $this->entityType, $this->entityId);
			if ($row === null)
			{
				throw new SystemException('Favorite row was not stored.');
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}

		// After the commit: the row of the list is assembled from committed state and its rights and
		// coverage reads must not join the write transaction.
		$item = $this->buildItem();

		$this->emitAdd($row['id'], $row['position'], $affectedPositions, $item);

		return $this->createResult([
			'success' => true,
			'id' => $row['id'],
			'position' => $row['position'],
			'affectedPositions' => $affectedPositions,
			'item' => $item,
		]);
	}

	/**
	 * [DTO-01] The stored row in the shape the favorites block renders, so a caller does not have to
	 * re-read the list after starring. Never fatal: the add itself is already committed, and the
	 * client falls back on re-reading when `item` is null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function buildItem(): ?array
	{
		try
		{
			return $this->provider->getRow($this->userId, $this->entityType, $this->entityId);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * [EVENT-01] favoriteAdd into the personal channel. The initiator is not excluded: the same user
	 * may have several tabs and the mobile app open at once.
	 *
	 * The row travels along: the channel belongs to that one user, whose own rights and coverage it
	 * was assembled with, so nothing personal leaves its owner.
	 *
	 * @param array<int, array{id: int, position: int}> $affectedPositions
	 * @param array<string, mixed>|null $item
	 */
	private function emitAdd(int $favoriteId, int $position, array $affectedPositions, ?array $item): void
	{
		$payload = [
			'id' => $favoriteId,
			'entityType' => $this->entityType,
			'entityId' => $this->entityId,
			'position' => $position,
			'affectedPositions' => $affectedPositions,
			'item' => $item,
		];

		$pushService = $this->pushService;
		$userId = $this->userId;
		$pushService->dispatchAfterCommit(static function () use ($pushService, $userId, $payload): void {
			$pushService->sendToUserChannel($userId, 'favoriteAdd', $payload);
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

<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;

/**
 * Takes one object out of the caller's favorites and reports where it stood, so a caller can put it
 * back. The notification subscription on the same object is a row of its own and stays untouched.
 *
 * ACL is deliberately not checked anywhere on this path: a user must be able to clean their own list
 * even after losing access to the object.
 */
final class RemoveFavoriteCommand extends AbstractCommand
{
	public function __construct(
		private readonly int $userId,
		private readonly string $entityType,
		private readonly int $entityId,
		private readonly FavoriteRepository $repository = new FavoriteRepository(),
		private readonly PushNotificationService $pushService = new PushNotificationService(),
	) {}

	protected function execute(): Result
	{
		$row = $this->repository->findRow($this->userId, $this->entityType, $this->entityId);
		if ($row === null)
		{
			return $this->createResult(['success' => true, 'removed' => null]);
		}

		$ordinal = $this->resolveOrdinal($row['id']);

		$this->repository->remove($this->userId, $this->entityType, $this->entityId);

		$this->emitRemove($row['id']);

		return $this->createResult([
			'success' => true,
			'removed' => [
				'position' => $ordinal,
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

	/**
	 * favoriteRemove into the personal channel. The initiator is not excluded: the same list
	 * may be open in another tab and in the mobile app.
	 */
	private function emitRemove(int $favoriteId): void
	{
		$payload = [
			'id' => $favoriteId,
			'entityType' => $this->entityType,
			'entityId' => $this->entityId,
		];

		$pushService = $this->pushService;
		$userId = $this->userId;
		$pushService->dispatchAfterCommit(static function () use ($pushService, $userId, $payload): void {
			$pushService->sendToUserChannel($userId, 'favoriteRemove', $payload);
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

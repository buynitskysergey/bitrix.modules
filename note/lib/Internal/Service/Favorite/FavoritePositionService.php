<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Favorite;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
use Bitrix\Note\Internal\Service\Document\Position\PositionCalculator;

/**
 * [P1.T3] Manual order of one user's favorites, by the same mechanics as
 * CollectionPositionService: gap positions, transactional renumber when the gaps are exhausted
 * and affectedPositions for the client. Documents and knowledge bases share one sequence, so
 * every read and write is scoped by $userId only.
 */
class FavoritePositionService
{
	private readonly FavoriteRepository $repository;
	private readonly PositionCalculator $calculator;

	public function __construct(
		?FavoriteRepository $repository = null,
		?PositionCalculator $calculator = null,
	)
	{
		$this->repository = $repository ?? new FavoriteRepository();
		$this->calculator = $calculator ?? new PositionCalculator();
	}

	/**
	 * POSITION for a row about to be inserted. Without an insertion point the row goes on top
	 * of the user's list, which is one step above the current maximum.
	 */
	public function allocate(int $userId, ?int $targetPosition): Result
	{
		$result = new Result();

		if ($userId <= 0)
		{
			$result->addError(new Error('Invalid user id.'));

			return $result;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$rows = $this->repository->getPositions($userId);

			if ($targetPosition === null)
			{
				$newPosition = $this->calculator->calculateNextPosition(
					$rows === [] ? 0 : (int)$rows[0]['position'],
				);
				$connection->commitTransaction();

				$result->setData([
					'position' => $newPosition,
					'affectedPositions' => [],
				]);

				return $result;
			}

			$renumbered = [];
			$newPosition = $this->calculator->calculateGapPosition($this->extractPositions($rows), $targetPosition);
			if ($newPosition === null)
			{
				$renumbered = $this->renumber($rows);
				$rows = $this->repository->getPositions($userId);
				$newPosition = $this->calculator->calculateGapPosition($this->extractPositions($rows), $targetPosition);
			}

			if ($newPosition === null)
			{
				$connection->rollbackTransaction();
				$result->addError(new Error('Unable to calculate favorite position.'));

				return $result;
			}

			$connection->commitTransaction();

			$result->setData([
				'position' => $newPosition,
				'affectedPositions' => $this->formatAffected($renumbered),
			]);

			return $result;
		}
		catch (\Throwable)
		{
			$this->rollbackQuietly($connection);
			$result->addError(new Error('Favorite position operation failed.'));

			return $result;
		}
	}

	/**
	 * Moves an existing row of the user to $targetPosition (ordinal, 1 is the topmost row).
	 * A row of another user is treated as absent: empty success, nothing written.
	 */
	public function move(int $userId, int $favoriteId, ?int $targetPosition): Result
	{
		$result = new Result();

		if ($userId <= 0 || $favoriteId <= 0)
		{
			$result->addError(new Error('Invalid favorite id.'));

			return $result;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$rows = $this->repository->getPositions($userId);
			if (!$this->contains($rows, $favoriteId))
			{
				$connection->commitTransaction();

				return $result;
			}

			$renumbered = [];
			$siblingPositions = $this->extractPositions($rows, $favoriteId);
			$newPosition = $this->calculator->calculateGapPosition($siblingPositions, $targetPosition);
			if ($newPosition === null)
			{
				$renumbered = $this->renumber($rows);
				$rows = $this->repository->getPositions($userId);
				$siblingPositions = $this->extractPositions($rows, $favoriteId);
				$newPosition = $this->calculator->calculateGapPosition($siblingPositions, $targetPosition);
			}

			if ($newPosition === null)
			{
				$connection->rollbackTransaction();
				$result->addError(new Error('Unable to calculate favorite position.'));

				return $result;
			}

			if (!$this->repository->updatePosition($favoriteId, $newPosition))
			{
				$connection->rollbackTransaction();
				$result->addError(new Error('Failed to update favorite position.'));

				return $result;
			}

			$connection->commitTransaction();

			// Final position of the dragged row always wins over an earlier renumber entry for the same id.
			$renumbered[$favoriteId] = $newPosition;

			$result->setData([
				'position' => $newPosition,
				'affectedPositions' => $this->formatAffected($renumbered),
			]);

			return $result;
		}
		catch (\Throwable)
		{
			$this->rollbackQuietly($connection);
			$result->addError(new Error('Favorite position operation failed.'));

			return $result;
		}
	}

	/**
	 * @param array<int, array{id: int, position: int}> $rows
	 * @return int[]
	 */
	private function extractPositions(array $rows, ?int $excludeId = null): array
	{
		$positions = [];
		foreach ($rows as $row)
		{
			if ($excludeId !== null && (int)$row['id'] === $excludeId)
			{
				continue;
			}

			$positions[] = (int)$row['position'];
		}

		return $positions;
	}

	/**
	 * @param array<int, array{id: int, position: int}> $rows
	 */
	private function contains(array $rows, int $favoriteId): bool
	{
		foreach ($rows as $row)
		{
			if ((int)$row['id'] === $favoriteId)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array{id: int, position: int}> $rows
	 * @return array<int, int> favoriteId => new position
	 */
	private function renumber(array $rows): array
	{
		$changes = [];
		$total = count($rows);
		foreach (array_values($rows) as $index => $row)
		{
			$id = (int)$row['id'];
			$desiredPosition = $this->calculator->calculateSequentialPosition($total - 1 - $index);
			if ((int)$row['position'] !== $desiredPosition)
			{
				$this->repository->updatePosition($id, $desiredPosition);
				$changes[$id] = $desiredPosition;
			}
		}

		return $changes;
	}

	/**
	 * @param array<int, int> $positionsById
	 * @return array<int, array{id: int, position: int}>
	 */
	private function formatAffected(array $positionsById): array
	{
		$affected = [];
		foreach ($positionsById as $id => $position)
		{
			$affected[] = ['id' => (int)$id, 'position' => (int)$position];
		}

		return $affected;
	}

	private function rollbackQuietly(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (\Throwable)
		{
		}
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Collection;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Service\Document\Position\PositionCalculator;

class CollectionPositionService
{
	private readonly CollectionRepository $repository;
	private readonly PositionCalculator $calculator;

	public function __construct(
		?CollectionRepository $repository = null,
		?PositionCalculator $calculator = null,
	)
	{
		$this->repository = $repository ?? new CollectionRepository();
		$this->calculator = $calculator ?? new PositionCalculator();
	}

	public function move(int $collectionId, ?int $targetPosition, int $userId): Result
	{
		return $this->apply($collectionId, $targetPosition, null, $userId);
	}

	/**
	 * Moves the collection next to a named neighbour instead of onto a place counted in a list.
	 *
	 * The order is one for the whole portal, but a person sees only the bases open to them, so the
	 * place counted in their panel points at someone else's gap: the second row of the panel can be
	 * the fifth row of the portal. The neighbour is the same row on both sides, so it says where the
	 * base must land no matter what is hidden around it.
	 */
	public function moveNextTo(int $collectionId, int $targetId, string $placement, int $userId): Result
	{
		$result = new Result();

		if ($targetId <= 0 || $targetId === $collectionId || !in_array($placement, ['before', 'after'], true))
		{
			$result->addError(new Error('Invalid collection move target.'));

			return $result;
		}

		return $this->apply($collectionId, null, ['id' => $targetId, 'placement' => $placement], $userId);
	}

	private function apply(int $collectionId, ?int $targetPosition, ?array $anchor, int $userId): Result
	{
		$result = new Result();

		if ($collectionId <= 0)
		{
			$result->addError(new Error('Invalid collection id.'));

			return $result;
		}

		$collection = $this->repository->getById($collectionId);
		if ($collection === null)
		{
			return $result;
		}

		if ($anchor !== null)
		{
			// The neighbour is looked up before the transaction opens, the way the REST resolver does
			// it: a move racing another one lands a place off, which is the same answer two people
			// dragging in one list get, and the order that comes out of it is still a valid order.
			$targetPosition = $this->resolveAnchorSlot($this->repository->getActivePositions(), $collectionId, $anchor);
			if ($targetPosition === null)
			{
				$result->addError(new Error('Collection move target is not in the list.'));

				return $result;
			}
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$rows = $this->repository->getActivePositions();
			$siblingPositions = $this->extractSiblingPositions($rows, $collectionId);

			$renumbered = [];
			$newPosition = $this->calculator->calculateGapPosition($siblingPositions, $targetPosition);
			if ($newPosition === null)
			{
				$renumbered = $this->renumber($rows, $userId);
				$rows = $this->repository->getActivePositions();
				$siblingPositions = $this->extractSiblingPositions($rows, $collectionId);
				$newPosition = $this->calculator->calculateGapPosition($siblingPositions, $targetPosition);
			}

			if ($newPosition === null)
			{
				$connection->rollbackTransaction();
				$result->addError(new Error('Unable to calculate collection position.'));

				return $result;
			}

			if (!$this->repository->updatePosition($collectionId, $newPosition, $userId))
			{
				$connection->rollbackTransaction();
				$result->addError(new Error('Failed to update collection position.'));

				return $result;
			}

			$connection->commitTransaction();

			// Final position of the dragged collection always wins over an earlier renumber entry for the same id.
			$renumbered[$collectionId] = $newPosition;
			$affected = [];
			foreach ($renumbered as $id => $position)
			{
				$affected[] = ['id' => (int)$id, 'position' => (int)$position];
			}

			$result->setData([
				'position' => $newPosition,
				'affectedPositions' => $affected,
			]);

			return $result;
		}
		catch (\Throwable)
		{
			try
			{
				$connection->rollbackTransaction();
			}
			catch (\Throwable)
			{
			}

			$result->addError(new Error('Collection position operation failed.'));

			return $result;
		}
	}

	private function resolveAnchorSlot(array $rows, int $movedId, array $anchor): ?int
	{
		$slot = 1;
		foreach ($rows as $row)
		{
			$id = (int)$row['id'];
			if ($id === $movedId)
			{
				continue;
			}

			if ($id === (int)$anchor['id'])
			{
				return $anchor['placement'] === 'before' ? $slot : $slot + 1;
			}

			$slot++;
		}

		return null;
	}

	private function extractSiblingPositions(array $rows, int $excludeId): array
	{
		$positions = [];
		foreach ($rows as $row)
		{
			if ((int)$row['id'] === $excludeId)
			{
				continue;
			}

			$positions[] = (int)$row['position'];
		}

		return $positions;
	}

	private function renumber(array $rows, int $userId): array
	{
		$changes = [];
		$total = count($rows);
		foreach (array_values($rows) as $index => $row)
		{
			$id = (int)$row['id'];
			$desiredPosition = $this->calculator->calculateSequentialPosition($total - 1 - $index);
			if ((int)$row['position'] !== $desiredPosition)
			{
				$this->repository->updatePosition($id, $desiredPosition, $userId);
				$changes[$id] = $desiredPosition;
			}
		}

		return $changes;
	}
}

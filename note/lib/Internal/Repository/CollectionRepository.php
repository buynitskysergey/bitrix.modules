<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\Result as OrmResult;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Note\Internal\Model\Collection;
use Bitrix\Note\Internal\Model\CollectionTable;

class CollectionRepository
{
	public const POSITION_GAP = 1000;

	public function save(Collection $collection): Result
	{
		$now = new DateTime();
		$collection->setUpdatedAt($now);
		if ($collection->getId() === null)
		{
			$collection->setCreatedAt($now);
		}

		$saveResult = $collection->save();

		if (!$saveResult->isSuccess())
		{
			return $this->buildFailedSaveResult($saveResult);
		}

		$result = new Result();
		$result->setData(['collection' => $collection]);

		return $result;
	}

	public function getList(
		?int $limit = null,
		?int $afterPosition = null,
		?int $afterId = null,
	): array
	{
		$query = CollectionTable::query()
			->setSelect(['ID', 'NAME', 'POSITION', 'POLICY_LEVEL', 'IS_ARCHIVED', 'CREATED_BY', 'UPDATED_BY', 'CREATED_AT', 'UPDATED_AT'])
			->where('IS_ARCHIVED', 'N')
			->addOrder('POSITION', 'DESC')
			->addOrder('ID', 'DESC')
		;

		$this->applyCursorFilter($query, $afterPosition, $afterId);

		if ($limit !== null && $limit > 0)
		{
			$query->setLimit($limit);
		}

		return $query->fetchCollection()->getAll();
	}

	public function getById(int $id): ?Collection
	{
		return CollectionTable::getByPrimary($id)->fetchObject();
	}

	/**
	 * Existence-only check: a single indexed PK lookup selecting just ID, no row hydration.
	 */
	public function exists(int $id): bool
	{
		if ($id <= 0)
		{
			return false;
		}

		return CollectionTable::getByPrimary($id, ['select' => ['ID']])->fetch() !== false;
	}

	public function getListByIds(
		array $ids,
		?int $limit = null,
		?int $afterPosition = null,
		?int $afterId = null,
	): array
	{
		if (empty($ids))
		{
			return [];
		}

		$query = CollectionTable::query()
			->setSelect(['ID', 'NAME', 'POSITION', 'POLICY_LEVEL', 'IS_ARCHIVED', 'CREATED_BY', 'UPDATED_BY', 'CREATED_AT', 'UPDATED_AT'])
			->whereIn('ID', $ids)
			->where('IS_ARCHIVED', 'N')
			->addOrder('POSITION', 'DESC')
			->addOrder('ID', 'DESC')
		;

		$this->applyCursorFilter($query, $afterPosition, $afterId);

		if ($limit !== null && $limit > 0)
		{
			$query->setLimit($limit);
		}

		return $query->fetchCollection()->getAll();
	}

	public function getMaxPosition(): int
	{
		$row = CollectionTable::getList([
			'select' => ['MAX_POSITION'],
			'runtime' => [
				new ExpressionField('MAX_POSITION', 'MAX(%s)', 'POSITION'),
			],
		])->fetch();

		return (int)($row['MAX_POSITION'] ?? 0);
	}

	public function reorderByIds(array $ids, int $userId): void
	{
		$normalizedIds = array_values(array_unique(array_map(static fn($id): int => (int)$id, $ids)));
		if (empty($normalizedIds))
		{
			return;
		}

		$existingItems = CollectionTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $normalizedIds)
			->where('IS_ARCHIVED', 'N')
			->fetchCollection()
		;

		$allowedIds = array_flip(array_map(static fn($item): int => (int)$item->getId(), $existingItems->getAll()));
		$total = count(array_intersect_key($allowedIds, array_flip($normalizedIds)));
		$now = new DateTime();

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$index = 0;
			foreach ($normalizedIds as $id)
			{
				if (!isset($allowedIds[$id]))
				{
					continue;
				}

				$position = self::POSITION_GAP * ($total - $index);
				$index++;
				CollectionTable::update($id, [
					'POSITION' => $position,
					'UPDATED_BY' => $userId,
					'UPDATED_AT' => $now,
				]);
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			throw $e;
		}
	}

	public function getActivePositions(): array
	{
		$rows = CollectionTable::query()
			->setSelect(['ID', 'POSITION'])
			->where('IS_ARCHIVED', 'N')
			->addOrder('POSITION', 'DESC')
			->addOrder('ID', 'DESC')
			->fetchAll()
		;

		return array_map(static fn(array $row): array => [
			'id' => (int)$row['ID'],
			'position' => (int)$row['POSITION'],
		], $rows);
	}

	public function updatePosition(int $id, int $position, int $userId): bool
	{
		$result = CollectionTable::update(
			$id,
			[
				'POSITION' => $position,
				'UPDATED_BY' => $userId,
				'UPDATED_AT' => new DateTime(),
			],
		);

		return $result->isSuccess();
	}

	public function deleteById(int $id): bool
	{
		$result = CollectionTable::delete($id);

		return $result->isSuccess();
	}

	/**
	 * Physically deletes a collection together with its access ACL and import map rows.
	 * Documents are NOT touched here — DeleteCollectionCommand moves them to the recycle bin separately.
	 * Transaction is managed by the caller (per MODULE.md transactions convention).
	 *
	 * Collection row is removed via CollectionTable::delete() so DataManager fires its
	 * cleanCache() hook — otherwise consumers using setCacheTtl() on CollectionTable would
	 * keep seeing the deleted row as alive (e.g. RecycleBinProvider orphan detection).
	 */
	public function hardDeleteById(int $id): void
	{
		if ($id <= 0)
		{
			return;
		}

		$connection = Application::getConnection();
		$idSafe = (int)$id;
		$connection->queryExecute("DELETE FROM b_note_collection_access WHERE COLLECTION_ID = {$idSafe}");
		$connection->queryExecute("DELETE FROM b_note_import_map WHERE COLLECTION_ID = {$idSafe}");
		// [P6.T1] Collection-scope subscriptions have no other cleanup hook — the
		// collection row is gone after this call, so ENTITY_ID would otherwise dangle.
		(new SubscriptionRepository())->deleteByCollectionId($id);

		CollectionTable::delete($id);
	}

	public function archiveById(int $id): bool
	{
		$result = CollectionTable::update($id, [
			'IS_ARCHIVED' => 'Y',
		]);

		return $result->isSuccess();
	}

	public function restoreById(int $id): bool
	{
		$result = CollectionTable::update($id, [
			'IS_ARCHIVED' => 'N',
		]);

		return $result->isSuccess();
	}

	private function applyCursorFilter(Query $query, ?int $afterPosition, ?int $afterId): void
	{
		if ($afterPosition !== null && $afterId !== null)
		{
			$query->where(
				Query::filter()->logic('or')
					->where('POSITION', '<', $afterPosition)
					->where(
						Query::filter()
							->where('POSITION', $afterPosition)
							->where('ID', '<', $afterId)
					)
			);
		}
	}

	private function buildFailedSaveResult(OrmResult $ormResult): Result
	{
		$result = new Result();
		foreach ($ormResult->getErrors() as $error)
		{
			$result->addError($error);
		}

		return $result;
	}
}

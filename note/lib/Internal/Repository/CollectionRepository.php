<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\Result as OrmResult;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Model\Collection;
use Bitrix\Note\Internal\Model\CollectionTable;
use Bitrix\Note\Internal\Util\CursorDate;

class CollectionRepository
{
	public const POSITION_GAP = 1000;

	// States a listing may ask for. The values are those of the read contract; the domain does not
	// reference the contract itself, the same way {@see DocumentRepository::STATE_LIVE} does not.
	public const STATE_LIVE = 'live';
	public const STATE_ARCHIVED = DocumentAccessService::STATE_ARCHIVED;
	public const STATE_TRASHED = DocumentAccessService::STATE_TRASHED;

	// TRASHED_AT rides along because the state of the base is answered from it: without it every
	// mapped row would fetch the column of its own.
	private const LIST_SELECT = [
		'ID', 'NAME', 'POSITION', 'POLICY_LEVEL', 'IS_ARCHIVED', 'TRASHED_AT',
		'CREATED_BY', 'UPDATED_BY', 'CREATED_AT', 'UPDATED_AT',
	];

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

	/**
	 * @param string[] $states self::STATE_*; the empty list keeps the live bases alone
	 */
	public function getList(
		?int $limit = null,
		?int $afterPosition = null,
		?int $afterId = null,
		array $states = [],
	): array
	{
		$query = CollectionTable::query()
			->setSelect(self::LIST_SELECT)
			->addOrder('POSITION', 'DESC')
			->addOrder('ID', 'DESC')
		;

		$this->applyStateFilter($query, $states);
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

	/**
	 * @param int[] $ids
	 * @param string[] $states self::STATE_*; the empty list keeps the live bases alone
	 */
	public function getListByIds(
		array $ids,
		?int $limit = null,
		?int $afterPosition = null,
		?int $afterId = null,
		array $states = [],
	): array
	{
		if (empty($ids))
		{
			return [];
		}

		$query = CollectionTable::query()
			->setSelect(self::LIST_SELECT)
			->whereIn('ID', $ids)
			->addOrder('POSITION', 'DESC')
			->addOrder('ID', 'DESC')
		;

		$this->applyStateFilter($query, $states);
		$this->applyCursorFilter($query, $afterPosition, $afterId);

		if ($limit !== null && $limit > 0)
		{
			$query->setLimit($limit);
		}

		return $query->fetchCollection()->getAll();
	}

	/**
	 * State of the given knowledge bases, without any of the state filters the listing methods
	 * apply: the section header of the recycle bin and the archive has to name a base precisely
	 * because it is not live, so filtering here would hide what the caller came for.
	 *
	 * @param int[] $ids
	 * @return array<int, array{id: int, name: string, isArchived: bool, isTrashed: bool, trashedAt: ?DateTime, trashedBy: ?int}>
	 */
	public function getStatesByIds(array $ids): array
	{
		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return [];
		}

		$out = [];
		foreach (array_chunk($normalized, 500) as $chunk)
		{
			$rows = CollectionTable::query()
				->setSelect(['ID', 'NAME', 'IS_ARCHIVED', 'TRASHED_AT', 'TRASHED_BY'])
				->whereIn('ID', $chunk)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$trashedAt = $row['TRASHED_AT'] ?? null;
				$out[(int)$row['ID']] = [
					'id' => (int)$row['ID'],
					'name' => (string)($row['NAME'] ?? ''),
					'isArchived' => ($row['IS_ARCHIVED'] ?? 'N') === 'Y',
					'isTrashed' => $trashedAt !== null,
					'trashedAt' => $trashedAt,
					'trashedBy' => isset($row['TRASHED_BY']) ? (int)$row['TRASHED_BY'] : null,
				];
			}
		}

		return $out;
	}

	public function getMaxPosition(): int
	{
		// A base in the recycle bin keeps its POSITION so that restoring it puts it back where it
		// was, but it must not push new bases past it: to the ordering of live bases it is absent.
		$row = CollectionTable::getList([
			'select' => ['MAX_POSITION'],
			'filter' => ['=TRASHED_AT' => null],
			'runtime' => [
				new ExpressionField('MAX_POSITION', 'MAX(%s)', 'POSITION'),
			],
		])->fetch();

		return (int)($row['MAX_POSITION'] ?? 0);
	}

	/**
	 * [ALG-01] Knowledge bases each of the given users created within the period, counted for the
	 * whole batch in one grouped query - the annual summary walks users in batches, and a per-user
	 * query would turn one scan into as many scans as there are users in the batch.
	 *
	 * Period bounds are half-open: from is included, to is not. Archived and physically alive
	 * collections both count - "how many did I create this year" is a historical fact, and a base
	 * deleted for good drops out on its own, together with its row.
	 *
	 * @param int[] $userIds
	 * @return array<int, int> userId => count, users with no collections are absent
	 */
	public function countCreatedByAuthors(array $userIds, Date $from, Date $to): array
	{
		$normalizedIds = $this->normalizeUserIds($userIds);
		if (empty($normalizedIds))
		{
			return [];
		}

		// GROUP BY CREATED_BY is added by the core: Query::hasAggregation() sees the aggregate in
		// the select list and groups the non-aggregate fields itself.
		$rows = CollectionTable::query()
			->registerRuntimeField(new ExpressionField('CNT', 'COUNT(*)'))
			->setSelect(['CREATED_BY', 'CNT'])
			->whereIn('CREATED_BY', $normalizedIds)
			->where('CREATED_AT', '>=', $this->toDateTime($from))
			->where('CREATED_AT', '<', $this->toDateTime($to))
			// A base that was deleted is not an achievement of the year: the summary counts what the
			// portal still has.
			->whereNull('TRASHED_AT')
			->fetchAll()
		;

		$counts = [];
		foreach ($rows as $row)
		{
			$counts[(int)$row['CREATED_BY']] = (int)$row['CNT'];
		}

		return $counts;
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private function normalizeUserIds(array $userIds): array
	{
		return array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $userIds),
			static fn(int $id): bool => $id > 0,
		)));
	}

	/**
	 * The annual summary hands over plain Date bounds; converting them explicitly keeps the
	 * comparison a datetime one on every driver instead of relying on implicit casting.
	 */
	private function toDateTime(Date $value): DateTime
	{
		return $value instanceof DateTime ? $value : DateTime::createFromTimestamp($value->getTimestamp());
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
			->whereNull('TRASHED_AT')
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
			->whereNull('TRASHED_AT')
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
		self::forgetCachedState($id);

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
		// Collection-scope subscriptions have no other cleanup hook - the
		// collection row is gone after this call, so ENTITY_ID would otherwise dangle.
		(new SubscriptionRepository())->deleteByCollectionId($id);

		CollectionTable::delete($id);
		self::forgetCachedState($id);
	}

	public function archiveById(int $id): bool
	{
		$result = CollectionTable::update($id, [
			'IS_ARCHIVED' => 'Y',
		]);
		self::forgetCachedState($id);

		return $result->isSuccess();
	}

	public function restoreById(int $id): bool
	{
		$result = CollectionTable::update($id, [
			'IS_ARCHIVED' => 'N',
		]);
		self::forgetCachedState($id);

		return $result->isSuccess();
	}

	/**
	 * Moves a knowledge base to the recycle bin. Conditional on purpose: two tabs deleting the same
	 * base, or a delete racing the cleanup agent, must not overwrite who deleted it and when. False
	 * means the base was already there, which is an idempotent outcome for the caller, not an error.
	 */
	public function trashById(int $id, int $userId): bool
	{
		if ($id <= 0)
		{
			return false;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$now = $helper->convertToDbDateTime(new DateTime());
		$connection->queryExecute(
			'UPDATE b_note_collection SET TRASHED_AT = ' . $now . ', TRASHED_BY = ' . (int)$userId
			. ' WHERE ID = ' . (int)$id . ' AND TRASHED_AT IS NULL'
		);
		$changed = $connection->getAffectedRowsCount() > 0;
		self::forgetCachedState($id);

		return $changed;
	}

	/**
	 * Brings a knowledge base back from the recycle bin. Conditional for the same reason as
	 * trashById(): false means someone else already restored it, or it has been purged for good.
	 */
	public function untrashById(int $id): bool
	{
		if ($id <= 0)
		{
			return false;
		}

		$connection = Application::getConnection();
		$connection->queryExecute(
			'UPDATE b_note_collection SET TRASHED_AT = NULL, TRASHED_BY = NULL'
			. ' WHERE ID = ' . (int)$id . ' AND TRASHED_AT IS NOT NULL'
		);
		$changed = $connection->getAffectedRowsCount() > 0;
		self::forgetCachedState($id);

		return $changed;
	}

	/**
	 * Marks the base as one whose destruction for good has begun. Called under the purge lock, before
	 * the first chunk of documents is destroyed: from this moment the base is no longer restorable,
	 * because a purge cut short by the time budget has already taken part of its documents away and
	 * nothing brings them back.
	 *
	 * Conditional like the two above: the first moment is the one that counts, and a purge resumed by
	 * the next run must not move it.
	 */
	public function markPurgeStarted(int $id): bool
	{
		if ($id <= 0)
		{
			return false;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$now = $helper->convertToDbDateTime(new DateTime());
		$connection->queryExecute(
			'UPDATE b_note_collection SET PURGE_STARTED_AT = ' . $now
			. ' WHERE ID = ' . (int)$id . ' AND PURGE_STARTED_AT IS NULL'
		);
		$changed = $connection->getAffectedRowsCount() > 0;
		self::forgetCachedState($id);

		return $changed;
	}

	public function isTrashed(int $id): bool
	{
		if ($id <= 0)
		{
			return false;
		}

		return !empty($this->filterTrashedIds([$id]));
	}

	/**
	 * Which of the given bases are in the recycle bin. The batch form is the one the listings need:
	 * asking per base turned one query into as many as there are rows on the page.
	 *
	 * @param int[] $ids
	 * @return int[] ids of the bases that are in the recycle bin, in no particular order
	 */
	public function filterTrashedIds(array $ids): array
	{
		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return [];
		}

		$out = [];
		foreach (array_chunk($normalized, 500) as $chunk)
		{
			$rows = CollectionTable::query()
				->setSelect(['ID'])
				->whereIn('ID', $chunk)
				->whereNotNull('TRASHED_AT')
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$out[] = (int)$row['ID'];
			}
		}

		return $out;
	}

	/**
	 * Bases lying in the recycle bin, freshest deletion first, keyset by (TRASHED_AT DESC, ID DESC).
	 *
	 * The section list of the bin reads this beside the tally of the bin itself, because a deleted
	 * base is a section of its own whether or not anything of it reached the bin as a row: a base
	 * holding no ordinary documents - which is every base just created - leaves exactly one bin row,
	 * that of its description, and a description is never the top of a section. Built on the rows
	 * alone the list had no section for such a base, and so no way back for it (AC-003, AC-005).
	 *
	 * Exclusive keyset, like the tally it is merged with: a page of sections names the base it stops
	 * at, and repeating it on the next page would draw the same section twice.
	 *
	 * @param int[]|null $collectionIds bases the viewer may see; null puts no restriction, the empty
	 *                                  list answers nothing
	 * @param array{freshness: string, collectionId: int}|null $cursor base the previous page stopped at
	 * @return array<int, string> collectionId => moment of deletion, freshest first
	 */
	public function listTrashedByFreshness(?array $collectionIds, int $limit, ?array $cursor = null): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$query = CollectionTable::query()
			->setSelect(['ID', 'TRASHED_AT'])
			->whereNotNull('TRASHED_AT')
			->addOrder('TRASHED_AT', 'DESC')
			->addOrder('ID', 'DESC')
			->setLimit($limit)
		;

		if ($collectionIds !== null)
		{
			$ids = array_values(array_unique(array_filter(
				array_map(static fn($id): int => (int)$id, $collectionIds),
				static fn(int $id): bool => $id > 0,
			)));
			if (empty($ids))
			{
				return [];
			}

			$query->whereIn('ID', $ids);
		}

		$at = CursorDate::parse($cursor['freshness'] ?? null);
		$afterId = isset($cursor['collectionId']) ? (int)$cursor['collectionId'] : 0;
		if ($at !== null && $afterId > 0)
		{
			$query->where(Query::filter()->logic('or')
				->where('TRASHED_AT', '<', $at)
				->where(Query::filter()
					->where('TRASHED_AT', $at)
					->where('ID', '<', $afterId)
				)
			);
		}

		$out = [];
		foreach ($query->fetchAll() as $row)
		{
			$trashedAt = $row['TRASHED_AT'] ?? null;
			$out[(int)$row['ID']] = $trashedAt instanceof DateTime
				? $trashedAt->format('Y-m-d H:i:s')
				: (string)($trashedAt ?? '')
			;
		}

		return $out;
	}

	/**
	 * Bases lying in the recycle bin, keyset by ID. Feeds the collection pass of emptying the bin,
	 * where the deadline plays no part - the caller decides which of them it may take away.
	 *
	 * @return int[]
	 */
	public function listTrashedIds(int $afterId, int $limit): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$rows = CollectionTable::query()
			->setSelect(['ID'])
			->whereNotNull('TRASHED_AT')
			->where('ID', '>', max(0, $afterId))
			->addOrder('ID', 'ASC')
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * Bases whose stay in the recycle bin is over, oldest first. Feeds the collection pass of the
	 * cleanup agent; the cutoff is the caller's, so the agent stays the only place that knows the TTL.
	 *
	 * @return int[]
	 */
	public function listTrashedBefore(DateTime $cutoff, int $limit): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$rows = CollectionTable::query()
			->setSelect(['ID'])
			->where('TRASHED_AT', '<', $cutoff)
			->addOrder('TRASHED_AT', 'ASC')
			->addOrder('ID', 'ASC')
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * The states a listing lets through, as an OR over the head of ix_note_collection_sort. Deletion
	 * outranks the archive, the same way the answer does: a base in the bin is trashed whatever its
	 * archive flag says.
	 *
	 * @param string[] $states
	 */
	private function applyStateFilter(Query $query, array $states): void
	{
		$live = $states === [] || in_array(self::STATE_LIVE, $states, true);
		$archived = in_array(self::STATE_ARCHIVED, $states, true);
		$trashed = in_array(self::STATE_TRASHED, $states, true);

		if ($live && $archived && $trashed)
		{
			return;
		}

		$predicate = Query::filter()->logic('or');
		if ($trashed)
		{
			$predicate->whereNotNull('TRASHED_AT');
		}

		if ($live || $archived)
		{
			$alive = Query::filter()->whereNull('TRASHED_AT');
			if (!$live)
			{
				$alive->where('IS_ARCHIVED', 'Y');
			}
			if (!$archived)
			{
				$alive->where('IS_ARCHIVED', 'N');
			}

			$predicate->where($alive);
		}

		$query->where($predicate);
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

	/**
	 * The request-lifetime view of the state of a base, dropped on every write of that state: within
	 * one request - a REST batch, say - what follows the write must not be answered from what
	 * preceded it.
	 */
	private static function forgetCachedState(int $id): void
	{
		DocumentAccessService::forgetCollectionState($id);
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

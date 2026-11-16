<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Ddl\DbType;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Entity\RecycleBin\RecycleBinRecord;
use Bitrix\Note\Internal\Model\CollectionTable;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\RecycleBinTable;
use Bitrix\Note\Internal\Repository\Mapper\RecycleBinRecordMapper;
use Bitrix\Note\Internal\Util\CursorDate;

class RecycleBinRepository
{
	private const FLAG_CHUNK = 500;

	/**
	 * Bulk INSERT IGNORE — on UNIQUE(DOCUMENT_ID) collision an already-trashed document is skipped
	 * without rolling back the batch.
	 *
	 * Every row is written with the two columns the section list reads - the base it belongs to and
	 * whether it is a deletion root - and the rows already in the bin under these documents stop
	 * being roots. Both halves are done here and not in the calling services: a row that reaches the
	 * table by any other route would be invisible in the list.
	 *
	 * @param RecycleBinRecord[] $records
	 */
	public function addBatch(array $records): void
	{
		if (empty($records))
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$chunks = array_chunk($records, self::FLAG_CHUNK);
		foreach ($chunks as $chunk)
		{
			$documentIds = [];
			foreach ($chunk as $record)
			{
				if ($record instanceof RecycleBinRecord)
				{
					$documentIds[] = $record->getDocumentId();
				}
			}

			// The batch itself counts as "in the bin": a chunk carries a parent and its child alike,
			// and the child is no root of anything once the parent lands beside it.
			$flags = $this->resolveFlags($documentIds, array_fill_keys($documentIds, true));

			$rows = [];
			foreach ($chunk as $record)
			{
				if (!$record instanceof RecycleBinRecord)
				{
					continue;
				}

				$row = RecycleBinRecordMapper::convertToOrm($record);
				$flag = $flags[$record->getDocumentId()] ?? ['collectionId' => 0, 'isRoot' => false];
				$rows[] = sprintf(
					'(%d, %s, %d, %s, %d, %s)',
					$row['DOCUMENT_ID'],
					$helper->convertToDbDateTime($row['TRASHED_AT']),
					$row['TRASHED_BY'],
					"'" . $helper->forSql($row['ORIGIN'], 40) . "'",
					$flag['collectionId'],
					$flag['isRoot'] ? "'" . RecycleBinTable::IS_ROOT_YES . "'" : "'" . RecycleBinTable::IS_ROOT_NO . "'",
				);
			}

			if (empty($rows))
			{
				continue;
			}

			$valuesClause = ' (DOCUMENT_ID, TRASHED_AT, TRASHED_BY, ORIGIN, COLLECTION_ID, IS_ROOT) VALUES '
				. implode(',', $rows)
			;
			// PG does not support INSERT IGNORE; ON CONFLICT DO NOTHING (bare) matches any constraint.
			if (DbType::getByConnectionType($connection->getType()) === DbType::PostgreSql)
			{
				$sql = 'INSERT INTO b_note_recycle_bin' . $valuesClause . ' ON CONFLICT DO NOTHING';
			}
			else
			{
				$sql = 'INSERT IGNORE INTO b_note_recycle_bin' . $valuesClause;
			}
			$connection->queryExecute($sql);

			$this->setChildrenRootFlag($this->groupByCollection($documentIds, $flags), false);
		}
	}

	/**
	 * The base and the root flag of documents about to be written into the bin, or of rows already
	 * there that need them recomputed.
	 *
	 * The base is stored rather than joined for, and it is stored as 0 when the base row is gone:
	 * a document keeps the id of the base it was deleted from, so the id alone would make a section
	 * out of a base nobody can name. Those rows are the technical "no knowledge base" section instead.
	 *
	 * @param int[] $documentIds
	 * @param array<int, true> $pending documents entering the bin in the same operation - parents
	 *        that are not in the table yet but will be by the time the operation ends
	 * @return array<int, array{collectionId: int, isRoot: bool}> keyed by documentId; a document that
	 *         no longer exists is absent
	 */
	private function resolveFlags(array $documentIds, array $pending = []): array
	{
		$ids = $this->normalizeIds($documentIds);
		if (empty($ids))
		{
			return [];
		}

		$documents = [];
		foreach (array_chunk($ids, self::FLAG_CHUNK) as $chunk)
		{
			$documents = array_merge($documents, DocumentTable::query()
				->setSelect(['ID', 'COLLECTION_ID', 'PARENT_ID', 'IS_MAIN'])
				->whereIn('ID', $chunk)
				->fetchAll()
			);
		}
		if (empty($documents))
		{
			return [];
		}

		$collectionIds = [];
		$parentIds = [];
		foreach ($documents as $row)
		{
			$collectionId = (int)($row['COLLECTION_ID'] ?? 0);
			if ($collectionId > 0)
			{
				$collectionIds[$collectionId] = true;
			}

			$parentId = (int)($row['PARENT_ID'] ?? 0);
			if ($parentId > 0 && !isset($pending[$parentId]))
			{
				$parentIds[$parentId] = true;
			}
		}

		$liveCollections = $this->filterExistingCollections(array_keys($collectionIds));
		$trashedParents = $this->filterTrashedDocumentIds(array_keys($parentIds));

		$out = [];
		foreach ($documents as $row)
		{
			$documentId = (int)$row['ID'];
			$collectionId = (int)($row['COLLECTION_ID'] ?? 0);
			$parentId = (int)($row['PARENT_ID'] ?? 0);
			$isMain = ($row['IS_MAIN'] ?? DocumentTable::IS_MAIN_NO) === DocumentTable::IS_MAIN_YES;
			$parentInBin = $parentId > 0 && (isset($pending[$parentId]) || isset($trashedParents[$parentId]));

			$out[$documentId] = [
				'collectionId' => isset($liveCollections[$collectionId]) ? $collectionId : 0,
				'isRoot' => !$isMain && !$parentInBin,
			];
		}

		return $out;
	}

	/**
	 * @param int[] $collectionIds
	 * @return array<int, true> keyed by the ids that still have a row
	 */
	private function filterExistingCollections(array $collectionIds): array
	{
		$ids = $this->normalizeIds($collectionIds);
		if (empty($ids))
		{
			return [];
		}

		$out = [];
		foreach (array_chunk($ids, self::FLAG_CHUNK) as $chunk)
		{
			$rows = CollectionTable::query()
				->setSelect(['ID'])
				->whereIn('ID', $chunk)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$out[(int)$row['ID']] = true;
			}
		}

		return $out;
	}

	/**
	 * @param int[] $documentIds
	 * @param array<int, array{collectionId: int, isRoot: bool}> $flags
	 * @return array<int, int[]> document ids grouped by their base; key 0 holds the ones whose base
	 *         is unknown - a document of a base already gone, or one that no longer exists
	 */
	private function groupByCollection(array $documentIds, array $flags): array
	{
		$out = [];
		foreach ($documentIds as $documentId)
		{
			$documentId = (int)$documentId;
			$collectionId = (int)($flags[$documentId]['collectionId'] ?? 0);
			$out[max(0, $collectionId)][] = $documentId;
		}

		return $out;
	}

	/**
	 * Corrects the root flag of the rows lying in the bin directly under the given documents: they
	 * stop being roots when a parent joins them there, and become roots again when it leaves.
	 *
	 * The children are looked up on the document table and not by walking the bin: the bin holds a
	 * whole deleted base, while the children of a handful of parents are a handful of rows. The base
	 * is what makes that lookup cheap - PARENT_ID leads no index of the document table, every branch
	 * index starts with the base - so the parents come in already grouped by it and each group is
	 * asked for on its own. One parent without a base then costs only its own group the narrowing
	 * instead of turning the whole batch into a scan of the index.
	 *
	 * @param array<int, int[]> $parentsByCollection parent document ids keyed by the base they lie
	 *        in; key 0 holds the ones whose base is unknown
	 */
	private function setChildrenRootFlag(array $parentsByCollection, bool $isRoot): void
	{
		$childIds = [];
		foreach ($parentsByCollection as $collectionId => $parentDocumentIds)
		{
			$parents = $this->normalizeIds($parentDocumentIds);
			if (empty($parents))
			{
				continue;
			}

			foreach (array_chunk($parents, self::FLAG_CHUNK) as $chunk)
			{
				$query = DocumentTable::query()
					->setSelect(['ID'])
					->where('IS_MAIN', DocumentTable::IS_MAIN_NO)
					->whereIn('PARENT_ID', $chunk)
				;
				if ((int)$collectionId > 0)
				{
					$query->where('COLLECTION_ID', (int)$collectionId);
				}

				foreach ($query->fetchAll() as $row)
				{
					$childIds[(int)$row['ID']] = true;
				}
			}
		}
		if (empty($childIds))
		{
			return;
		}

		$from = $isRoot ? RecycleBinTable::IS_ROOT_NO : RecycleBinTable::IS_ROOT_YES;
		$to = $isRoot ? RecycleBinTable::IS_ROOT_YES : RecycleBinTable::IS_ROOT_NO;
		$connection = Application::getConnection();
		foreach (array_chunk(array_keys($childIds), self::FLAG_CHUNK) as $chunk)
		{
			$list = implode(',', $chunk);
			$connection->queryExecute(
				"UPDATE b_note_recycle_bin SET IS_ROOT = '{$to}' WHERE IS_ROOT = '{$from}' AND DOCUMENT_ID IN ({$list})"
			);
		}
	}

	/**
	 * Fills COLLECTION_ID and IS_ROOT for one forward page of rows written before the two columns
	 * existed. Portion by portion and by key: the bin of a portal that deleted a knowledge base holds
	 * tens of thousands of rows, and a single pass over all of them would hold the table for the whole
	 * of it.
	 *
	 * @return int|null the key the page stopped at, or null when there is nothing left to fill
	 */
	public function backfillFlagsPage(int $afterId, int $limit): ?int
	{
		if ($limit <= 0)
		{
			return null;
		}

		$rows = RecycleBinTable::query()
			->setSelect(['ID', 'DOCUMENT_ID'])
			->where('ID', '>', max(0, $afterId))
			->addOrder('ID', 'ASC')
			->setLimit($limit)
			->fetchAll()
		;
		if (empty($rows))
		{
			return null;
		}

		$documentIds = array_map(static fn(array $row): int => (int)$row['DOCUMENT_ID'], $rows);
		$flags = $this->resolveFlags($documentIds);

		// One statement per distinct pair of values instead of one per row: a page of a base deleted
		// whole carries the same base and the same answer over and over.
		$groups = [];
		$lastId = 0;
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			$lastId = max($lastId, $id);
			// A row whose document is gone is listed by nothing and stays at the default.
			$flag = $flags[(int)$row['DOCUMENT_ID']] ?? ['collectionId' => 0, 'isRoot' => false];
			$groups[$flag['collectionId'] . ':' . ($flag['isRoot'] ? '1' : '0')][] = $id;
		}

		$connection = Application::getConnection();
		foreach ($groups as $key => $ids)
		{
			[$collectionId, $isRoot] = explode(':', $key);
			$flagValue = $isRoot === '1' ? RecycleBinTable::IS_ROOT_YES : RecycleBinTable::IS_ROOT_NO;
			foreach (array_chunk($ids, self::FLAG_CHUNK) as $chunk)
			{
				$list = implode(',', $chunk);
				// Only rows still at the pre-column default: a row a concurrent restore or delete has
				// already corrected must not be overwritten with the value read before that change.
				$connection->queryExecute(
					'UPDATE b_note_recycle_bin'
					. " SET COLLECTION_ID = " . (int)$collectionId . ", IS_ROOT = '{$flagValue}'"
					. " WHERE ID IN ({$list})"
					. " AND COLLECTION_ID = 0 AND IS_ROOT = '" . RecycleBinTable::IS_ROOT_NO . "'"
				);
				// A row an exit from the bin has already raised to the top still lacks its base: the exit
				// corrects the flag alone. The flag it was given stands, and the base is filled in, or the
				// row would be listed by nothing - no section of its base and no orphan either.
				if ((int)$collectionId > 0)
				{
					$connection->queryExecute(
						'UPDATE b_note_recycle_bin SET COLLECTION_ID = ' . (int)$collectionId
						. " WHERE ID IN ({$list}) AND COLLECTION_ID = 0"
					);
				}
			}
		}

		return $lastId;
	}

	public function getById(int $recycleBinId): ?RecycleBinRecord
	{
		if ($recycleBinId <= 0)
		{
			return null;
		}

		$row = RecycleBinTable::query()
			->setSelect(['ID', 'DOCUMENT_ID', 'TRASHED_AT', 'TRASHED_BY', 'ORIGIN'])
			->where('ID', $recycleBinId)
			->fetch();

		if ($row === false)
		{
			return null;
		}

		return RecycleBinRecordMapper::convertFromOrm($row);
	}

	/**
	 * @param int[] $ids
	 * @return RecycleBinRecord[] indexed by entry id, in input order skipped
	 */
	public function getByIds(array $ids): array
	{
		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return [];
		}

		$rows = RecycleBinTable::query()
			->setSelect(['ID', 'DOCUMENT_ID', 'TRASHED_AT', 'TRASHED_BY', 'ORIGIN'])
			->whereIn('ID', $normalized)
			->fetchAll();

		$out = [];
		foreach ($rows as $row)
		{
			$record = RecycleBinRecordMapper::convertFromOrm($row);
			$out[(int)$record->getId()] = $record;
		}

		return $out;
	}

	public function getByDocumentId(int $documentId): ?RecycleBinRecord
	{
		if ($documentId <= 0)
		{
			return null;
		}

		$row = RecycleBinTable::query()
			->setSelect(['ID', 'DOCUMENT_ID', 'TRASHED_AT', 'TRASHED_BY', 'ORIGIN'])
			->where('DOCUMENT_ID', $documentId)
			->fetch();

		if ($row === false)
		{
			return null;
		}

		return RecycleBinRecordMapper::convertFromOrm($row);
	}

	/**
	 * @param int[] $documentIds
	 * @return array<int, RecycleBinRecord> indexed by documentId
	 */
	public function getByDocumentIds(array $documentIds): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $documentIds), static fn (int $id) => $id > 0)));
		if (empty($ids))
		{
			return [];
		}

		$rows = RecycleBinTable::query()
			->setSelect(['ID', 'DOCUMENT_ID', 'TRASHED_AT', 'TRASHED_BY', 'ORIGIN'])
			->whereIn('DOCUMENT_ID', $ids)
			->fetchAll();

		$result = [];
		foreach ($rows as $row)
		{
			$record = RecycleBinRecordMapper::convertFromOrm($row);
			$result[(int)$record->getDocumentId()] = $record;
		}

		return $result;
	}

	/**
	 * Lightweight projection used by cascade pushes — only ID + TRASHED_AT, no mapper hop.
	 *
	 * @param int[] $documentIds
	 * @return array<int, array{id: int, trashedAt: string}> keyed by documentId
	 */
	public function getIdsByDocumentIds(array $documentIds): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $documentIds), static fn (int $id) => $id > 0)));
		if (empty($ids))
		{
			return [];
		}

		$rows = RecycleBinTable::query()
			->setSelect(['ID', 'DOCUMENT_ID', 'TRASHED_AT'])
			->whereIn('DOCUMENT_ID', $ids)
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$trashedAt = $row['TRASHED_AT'] ?? null;
			$result[(int)$row['DOCUMENT_ID']] = [
				'id' => (int)$row['ID'],
				'trashedAt' => $trashedAt instanceof DateTime ? $trashedAt->format('Y-m-d H:i:s') : (string)($trashedAt ?? ''),
			];
		}

		return $result;
	}

	/**
	 * Which of the given documents lie in the bin. Batch form of {@see isInRecycleBin()} for pages
	 * that need the state of a whole set - a path, a level - instead of one probe per row.
	 *
	 * @param int[] $documentIds
	 * @return array<int, true> keyed by documentId
	 */
	public function filterTrashedDocumentIds(array $documentIds): array
	{
		$ids = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($ids))
		{
			return [];
		}

		$out = [];
		foreach (array_chunk($ids, 500) as $chunk)
		{
			$rows = RecycleBinTable::query()
				->setSelect(['DOCUMENT_ID'])
				->whereIn('DOCUMENT_ID', $chunk)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$out[(int)$row['DOCUMENT_ID']] = true;
			}
		}

		return $out;
	}

	public function isInRecycleBin(int $documentId): bool
	{
		if ($documentId <= 0)
		{
			return false;
		}

		$row = RecycleBinTable::query()
			->setSelect(['ID'])
			->where('DOCUMENT_ID', $documentId)
			->setLimit(1)
			->fetch();

		return $row !== false;
	}

	/**
	 * By document and not by key, although the key is what the caller holds: the departure of a row
	 * promotes the rows under it, and that is one path for every way out of the bin.
	 */
	public function deleteById(int $recycleBinId): void
	{
		if ($recycleBinId <= 0)
		{
			return;
		}

		$record = $this->getById($recycleBinId);
		if ($record === null)
		{
			return;
		}

		$this->deleteByDocumentIds([$record->getDocumentId()]);
	}

	public function deleteByDocumentId(int $documentId): void
	{
		$this->deleteByDocumentIds([$documentId]);
	}

	/**
	 * Takes rows out of the bin, whichever way out it is - a restore, an emptying, the cleanup agent
	 * or a hard delete - and hands the rows that were lying under them their root flag back: their
	 * parent is no longer beside them, so they are the top of what is left.
	 *
	 * The bases are read off the departing rows before they go, because the children are looked up by
	 * base, and the document of a departing row may already be gone by now (a hard delete removes it
	 * first).
	 *
	 * @param int[] $documentIds
	 */
	public function deleteByDocumentIds(array $documentIds): void
	{
		$normalized = $this->normalizeIds($documentIds);
		if (empty($normalized))
		{
			return;
		}

		$leavingByCollection = [];
		foreach (array_chunk($normalized, self::FLAG_CHUNK) as $chunk)
		{
			$rows = RecycleBinTable::query()
				->setSelect(['DOCUMENT_ID', 'COLLECTION_ID'])
				->whereIn('DOCUMENT_ID', $chunk)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$collectionId = max(0, (int)($row['COLLECTION_ID'] ?? 0));
				$leavingByCollection[$collectionId][] = (int)$row['DOCUMENT_ID'];
			}
		}

		$connection = Application::getConnection();
		$placeholders = implode(',', $normalized);
		$connection->queryExecute("DELETE FROM b_note_recycle_bin WHERE DOCUMENT_ID IN ({$placeholders})");

		$this->setChildrenRootFlag($leavingByCollection, true);
	}

	/**
	 * Entries of one base with the given origin. Keyset by DOCUMENT_ID so that a caller can walk the
	 * base and delete the rows it has already taken in the same pass.
	 *
	 * @return array<int, array{documentId: int, isArchived: bool, isMain: bool}>
	 */
	public function listByCollectionAndOrigin(
		int $collectionId,
		string $origin,
		int $afterDocumentId,
		int $limit,
	): array
	{
		if ($collectionId <= 0 || $limit <= 0)
		{
			return [];
		}

		$rows = RecycleBinTable::query()
			->setSelect([
				'DOCUMENT_ID',
				'IS_ARCHIVED' => 'DOCUMENT.IS_ARCHIVED',
				'IS_MAIN' => 'DOCUMENT.IS_MAIN',
			])
			->where('ORIGIN', $origin)
			->where('DOCUMENT.COLLECTION_ID', $collectionId)
			->where('DOCUMENT_ID', '>', max(0, $afterDocumentId))
			->addOrder('DOCUMENT_ID', 'ASC')
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(
			static fn(array $row): array => [
				'documentId' => (int)$row['DOCUMENT_ID'],
				'isArchived' => ($row['IS_ARCHIVED'] ?? 'N') === 'Y',
				'isMain' => ($row['IS_MAIN'] ?? DocumentTable::IS_MAIN_NO) === DocumentTable::IS_MAIN_YES,
			],
			$rows,
		);
	}

	/**
	 * Entries the cleanup agent may take away. Documents of a base that is itself in the bin are left
	 * out: such a base is purged whole and under a lock, so that a restore in flight cannot lose them.
	 * Orphans stay in - their collection row is gone, and the join gives them NULL here.
	 *
	 * @return RecycleBinRecord[]
	 */
	public function listExpiredAt(DateTime $cutoff, int $limit): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$rows = RecycleBinTable::query()
			->setSelect(['ID', 'DOCUMENT_ID', 'TRASHED_AT', 'TRASHED_BY', 'ORIGIN'])
			->whereNull('DOCUMENT.COLLECTION.TRASHED_AT')
			->where('TRASHED_AT', '<', $cutoff)
			->addOrder('TRASHED_AT', 'ASC')
			->addOrder('ID', 'ASC')
			->setLimit($limit)
			->fetchAll();

		return array_map(
			static fn(array $row): RecycleBinRecord => RecycleBinRecordMapper::convertFromOrm($row),
			$rows,
		);
	}

	/**
	 * Deletion roots: entries whose document is not the description of a base and has no parent lying
	 * in the bin beside it. ORIGIN cannot answer that - a deleted knowledge base stamps every one of
	 * its rows the same way - so the answer is written into the row and kept there by every write
	 * path (see addBatch() and deleteByDocumentIds()).
	 *
	 * Kept rather than derived because deriving it cost the whole bin: the walk DOCUMENT -> PARENT ->
	 * RECYCLE_BIN had to be made for every row before the first section could be named, and a LIMIT
	 * over a grouped, aggregate-ordered read stops nothing. Read from the row, the same list is one
	 * index range of this table.
	 */
	private function queryRoots(): Query
	{
		return RecycleBinTable::query()->where('IS_ROOT', RecycleBinTable::IS_ROOT_YES);
	}

	/**
	 * Keyset over (TRASHED_AT DESC, ID DESC), inclusive of the given key.
	 *
	 * Inclusive and not exclusive on purpose: the same field carries both the first-page key of a
	 * section and nextCursor, and nextCursor names the first row of the NEXT page rather than the
	 * last of this one. One meaning for the value everywhere it travels.
	 *
	 * @param array{changedAt: string, id: int}|null $cursor
	 */
	private function applyCursor(Query $query, ?array $cursor): void
	{
		$at = CursorDate::parse($cursor['changedAt'] ?? null);
		$id = isset($cursor['id']) ? (int)$cursor['id'] : 0;
		if ($at === null || $id <= 0)
		{
			return;
		}

		$query->where(Query::filter()->logic('or')
			->where('TRASHED_AT', '<', $at)
			->where(Query::filter()
				->where('TRASHED_AT', $at)
				->where('ID', '<=', $id)
			)
		);
	}

	/**
	 * Keyset of the section list, over (freshest first, id second). The freshness of a section is an
	 * aggregate - the latest deletion inside the base - so the condition lands in HAVING, which is
	 * where the ORM puts a condition on an aggregated field.
	 *
	 * Exclusive, unlike the keyset inside a section: a page of sections names the base it stops at,
	 * and repeating it on the next page would draw the same section twice.
	 *
	 * @param array{freshness: string, collectionId: int}|null $cursor
	 */
	private function applyFreshnessCursor(Query $query, ?array $cursor): void
	{
		$at = CursorDate::parse($cursor['freshness'] ?? null);
		$id = isset($cursor['collectionId']) ? (int)$cursor['collectionId'] : 0;
		if ($at === null || $id <= 0)
		{
			return;
		}

		$query->where(Query::filter()->logic('or')
			->where('LATEST_AT', '<', $at)
			->where(Query::filter()
				->where('LATEST_AT', $at)
				->where('COLLECTION_ID', '<', $id)
			)
		);
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private function normalizeIds(array $ids): array
	{
		return array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
	}

	/**
	 * @return array<int, array{id: int, documentId: int, changedAt: string}>
	 */
	private function fetchPage(Query $query, int $limit): array
	{
		$rows = $query
			->setSelect(['ID', 'DOCUMENT_ID', 'TRASHED_AT'])
			->addOrder('TRASHED_AT', 'DESC')
			->addOrder('ID', 'DESC')
			->setLimit(max(1, $limit))
			->fetchAll()
		;

		$out = [];
		foreach ($rows as $row)
		{
			$trashedAt = $row['TRASHED_AT'] ?? null;
			$out[] = [
				'id' => (int)$row['ID'],
				'documentId' => (int)$row['DOCUMENT_ID'],
				'changedAt' => $trashedAt instanceof DateTime
					? $trashedAt->format('Y-m-d H:i:s')
					: (string)($trashedAt ?? ''),
			];
		}

		return $out;
	}

	/**
	 * One page of the top level of a knowledge base's section in the bin.
	 *
	 * @param array{changedAt: string, id: int}|null $cursor
	 * @return array<int, array{id: int, documentId: int, changedAt: string}>
	 */
	public function listRootPage(int $collectionId, ?array $cursor, int $limit): array
	{
		if ($collectionId <= 0)
		{
			return [];
		}

		$query = $this->queryRoots()->where('COLLECTION_ID', $collectionId);
		$this->applyCursor($query, $cursor);

		return $this->fetchPage($query, $limit);
	}

	/**
	 * Top level of several sections at once, in one pass over the same keyset the single-section read
	 * uses. The window is bounded by $limit overall: rows come freshest-first, and the sections of the
	 * bin are ordered by that same freshness, so the window fills the sections the reader lands on.
	 *
	 * @param int[] $collectionIds
	 * @return array<int, array<int, array{id: int, documentId: int, changedAt: string}>> by collection id
	 */
	public function listRootPagesByCollection(array $collectionIds, int $limit): array
	{
		$ids = $this->normalizeIds($collectionIds);
		if (empty($ids))
		{
			return [];
		}

		$rows = $this->queryRoots()
			->whereIn('COLLECTION_ID', $ids)
			->setSelect(['ID', 'DOCUMENT_ID', 'TRASHED_AT', 'COLLECTION_ID'])
			->addOrder('TRASHED_AT', 'DESC')
			->addOrder('ID', 'DESC')
			->setLimit(max(1, $limit))
			->fetchAll()
		;

		$out = [];
		foreach ($rows as $row)
		{
			$collectionId = (int)($row['COLLECTION_ID'] ?? 0);
			if ($collectionId <= 0)
			{
				continue;
			}

			$trashedAt = $row['TRASHED_AT'] ?? null;
			$out[$collectionId][] = [
				'id' => (int)$row['ID'],
				'documentId' => (int)$row['DOCUMENT_ID'],
				'changedAt' => $trashedAt instanceof DateTime
					? $trashedAt->format('Y-m-d H:i:s')
					: (string)($trashedAt ?? ''),
			];
		}

		return $out;
	}

	/**
	 * Technical "no knowledge base" section: the source knowledge base is gone entirely - deleting
	 * a base is immediate and final, and it leaves its whole tree behind as orphans rather than
	 * taking it along - and the user either trashed the document or wrote it. The list inside is
	 * flat - an orphan's structure is torn.
	 *
	 * @param array{changedAt: string, id: int}|null $cursor
	 * @return array<int, array{id: int, documentId: int, changedAt: string}>
	 */
	public function listOrphanPage(?int $userId, ?array $cursor, int $limit): array
	{
		$query = $this->queryOrphans($userId);
		$this->applyCursor($query, $cursor);

		return $this->fetchPage($query, $limit);
	}

	/**
	 * The same orphans for the document list, which pages every one of its streams by the document
	 * alone. The keyset is therefore the document identifier itself and not the freshness pair the
	 * bin screen reads by: a cursor that carries the key it orders by survives the row it names
	 * leaving the bin between pages.
	 *
	 * @return int[] document ids, descending
	 */
	public function listOrphanDocumentPage(?int $userId, ?int $afterDocumentId, int $limit): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$query = $this->queryOrphans($userId)
			->setSelect(['DOCUMENT_ID'])
			->addOrder('DOCUMENT_ID', 'DESC')
			->setLimit($limit)
		;
		if ($afterDocumentId !== null && $afterDocumentId > 0)
		{
			$query->where('DOCUMENT_ID', '<', $afterDocumentId);
		}

		return array_map(static fn(array $row): int => (int)$row['DOCUMENT_ID'], $query->fetchAll());
	}

	private function queryOrphans(?int $userId): Query
	{
		$query = RecycleBinTable::query()
			->where('DOCUMENT.IS_MAIN', DocumentTable::IS_MAIN_NO)
			->whereNull('DOCUMENT.COLLECTION.ID')
		;

		if ($userId !== null)
		{
			$query->where(Query::filter()->logic('or')
				->where('TRASHED_BY', $userId)
				->where('DOCUMENT.CREATED_BY', $userId)
			);
		}

		return $query;
	}

	/**
	 * One page of the trashed documents of several knowledge bases at once, flat and in the order
	 * the document list pages by: (COLLECTION_ID DESC, DOCUMENT_ID DESC), which is
	 * ix_note_recycle_bin_collection head to toe. Read from the bin and not from the documents on
	 * purpose - the same page taken by scanning documents for bin rows costs the archive of the
	 * whole portal, and ix_note_recycle_bin_root cannot serve it either: it starts with IS_ROOT,
	 * and a flat page names every trashed document, not only the tops of the sections.
	 *
	 * The description of a knowledge base is the one row dropped here, and the flag saying so lives
	 * on the document: the page pays a primary-key read per row for it.
	 *
	 * @param int[]|null $collectionIds null puts no restriction, the empty list answers nothing
	 * @param array{collectionId: int, id: int}|null $cursor last row of the previous page, exclusive
	 * @return array<int, array{id: int, collectionId: int}> document ids with their base
	 */
	public function listByCollectionsPage(?array $collectionIds, ?array $cursor, int $limit): array
	{
		$ids = $collectionIds === null ? null : $this->normalizeIds($collectionIds);
		if ($ids === [] || $limit <= 0)
		{
			return [];
		}

		$query = RecycleBinTable::query()
			->setSelect(['DOCUMENT_ID', 'COLLECTION_ID'])
			->where('DOCUMENT.IS_MAIN', DocumentTable::IS_MAIN_NO)
			->addOrder('COLLECTION_ID', 'DESC')
			->addOrder('DOCUMENT_ID', 'DESC')
			->setLimit($limit)
		;
		if ($ids === null)
		{
			// The copy of the base kept here outlives the base itself, so "gone" is asked of the
			// base row and not of this column: a document with no base left belongs to the stream of
			// orphans alone and must not be handed out by this one as well.
			$query->where('COLLECTION_ID', '>', 0)->whereNotNull('DOCUMENT.COLLECTION.ID');
		}
		else
		{
			$query->whereIn('COLLECTION_ID', $ids);
		}

		$afterCollectionId = isset($cursor['collectionId']) ? (int)$cursor['collectionId'] : 0;
		$afterId = isset($cursor['id']) ? (int)$cursor['id'] : 0;
		if ($afterCollectionId > 0 && $afterId > 0)
		{
			$query->where(Query::filter()->logic('or')
				->where('COLLECTION_ID', '<', $afterCollectionId)
				->where(Query::filter()
					->where('COLLECTION_ID', $afterCollectionId)
					->where('DOCUMENT_ID', '<', $afterId)
				)
			);
		}

		$out = [];
		foreach ($query->fetchAll() as $row)
		{
			$out[] = [
				'id' => (int)$row['DOCUMENT_ID'],
				'collectionId' => (int)$row['COLLECTION_ID'],
			];
		}

		return $out;
	}

	/**
	 * Children of a node inside the bin: one level, same state as the node itself.
	 *
	 * @param array{changedAt: string, id: int}|null $cursor
	 * @return array<int, array{id: int, documentId: int, changedAt: string}>
	 */
	public function listChildPage(int $parentDocumentId, ?array $cursor, int $limit): array
	{
		if ($parentDocumentId <= 0)
		{
			return [];
		}

		$query = RecycleBinTable::query()
			->where('DOCUMENT.IS_MAIN', DocumentTable::IS_MAIN_NO)
			->where('DOCUMENT.PARENT_ID', $parentDocumentId)
		;
		$this->applyCursor($query, $cursor);

		return $this->fetchPage($query, $limit);
	}

	/**
	 * Deletion roots of every knowledge base at once: what the section list needs, in one
	 * pass over the bin. Asking base by base was the same pass repeated once per base, because a
	 * base holding less than the display ceiling never lets the query stop early - fifty sections
	 * meant fifty walks of the same rows.
	 *
	 * The freshest deletion of each base comes along for free, and it both orders the sections and
	 * decides which of them the answer has room for: the bound belongs on the bases the bin holds
	 * something for, never on a prefix of the portal's bases.
	 *
	 * A base that is gone forms no group here - its rows carry no base at all and are the technical
	 * "no knowledge base" section instead (see countOrphansCapped()).
	 *
	 * @param int[]|null $collectionIds knowledge bases the viewer may see; null puts no restriction,
	 *                                  the empty list answers nothing
	 * @return array<int, array{count: int, latestAt: string}> collectionId => tally, freshest first
	 */
	public function tallyRootsByFreshness(?array $collectionIds, int $limit, ?array $afterCursor = null): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$query = $this->queryRoots()->where('COLLECTION_ID', '>', 0);
		if ($collectionIds !== null)
		{
			$ids = $this->normalizeIds($collectionIds);
			if (empty($ids))
			{
				return [];
			}

			$query->whereIn('COLLECTION_ID', $ids);
		}

		$query
			->registerRuntimeField('ROOT_COUNT', new ExpressionField('ROOT_COUNT', 'COUNT(%s)', 'ID'))
			// Typed on purpose: the keyset of the page compares against this field, and an untyped
			// expression takes a date as the site formats it for a human - which is not a date the
			// database will read back.
			->registerRuntimeField('LATEST_AT', new ExpressionField(
				'LATEST_AT',
				'MAX(%s)',
				'TRASHED_AT',
				['data_type' => 'datetime'],
			))
			->setSelect(['COLLECTION_ID', 'ROOT_COUNT', 'LATEST_AT'])
			->addOrder('LATEST_AT', 'DESC')
			->addOrder('COLLECTION_ID', 'DESC')
			->setLimit($limit)
		;
		$this->applyFreshnessCursor($query, $afterCursor);

		$rows = $query->fetchAll();

		$out = [];
		foreach ($rows as $row)
		{
			$collectionId = (int)($row['COLLECTION_ID'] ?? 0);
			if ($collectionId <= 0)
			{
				continue;
			}

			$latest = $row['LATEST_AT'] ?? null;
			$out[$collectionId] = [
				'count' => (int)($row['ROOT_COUNT'] ?? 0),
				'latestAt' => $latest instanceof DateTime
					? $latest->format('Y-m-d H:i:s')
					: (string)($latest ?? ''),
			];
		}

		return $out;
	}

	/**
	 * Same ceiling, applied to the technical section.
	 */
	public function countOrphansCapped(?int $userId, int $ceiling): int
	{
		if ($ceiling <= 0)
		{
			return 0;
		}

		$query = RecycleBinTable::query()
			->setSelect(['ID'])
			->where('DOCUMENT.IS_MAIN', DocumentTable::IS_MAIN_NO)
			->whereNull('DOCUMENT.COLLECTION.ID')
			->setLimit($ceiling + 1)
		;

		if ($userId !== null)
		{
			$query->where(Query::filter()->logic('or')
				->where('TRASHED_BY', $userId)
				->where('DOCUMENT.CREATED_BY', $userId)
			);
		}

		return count($query->fetchAll());
	}

	/**
	 * Direct children of each of the given documents that lie in the bin.
	 *
	 * The count is asked by parent, but the parent lives on the document table, and every branch
	 * index there starts with COLLECTION_ID. Without the base the cheapest path the optimizer had
	 * was to read the documents of the portal off a covering index and probe the bin for each of
	 * them; with it the branch index answers, and a bin holding little decides the rest.
	 *
	 * @param int[] $collectionIds bases the parents live in; the empty list counts nothing
	 * @param int[] $parentDocumentIds
	 * @param ConditionTree|null $visibility predicate over DOCUMENT.ID / DOCUMENT.COLLECTION_ID;
	 *                                       null counts everything
	 * @return array<int, int> parentDocumentId => children in the bin
	 */
	public function getChildCountMap(
		array $collectionIds,
		array $parentDocumentIds,
		?ConditionTree $visibility = null,
	): array
	{
		$ids = $this->normalizeIds($parentDocumentIds);
		$collections = $this->normalizeIds($collectionIds);
		if (empty($ids) || empty($collections))
		{
			return [];
		}

		$query = RecycleBinTable::query()
			->registerRuntimeField('CHILD_COUNT', new ExpressionField('CHILD_COUNT', 'COUNT(%s)', 'ID'))
			->setSelect(['PARENT_ID' => 'DOCUMENT.PARENT_ID', 'CHILD_COUNT'])
			->where('DOCUMENT.IS_MAIN', DocumentTable::IS_MAIN_NO)
			->whereIn('DOCUMENT.COLLECTION_ID', $collections)
			->whereIn('DOCUMENT.PARENT_ID', $ids)
		;
		if ($visibility !== null)
		{
			$query->where($visibility);
		}

		$rows = $query->fetchAll();

		$out = [];
		foreach ($rows as $row)
		{
			$parentId = (int)($row['PARENT_ID'] ?? 0);
			if ($parentId > 0)
			{
				$out[$parentId] = (int)($row['CHILD_COUNT'] ?? 0);
			}
		}

		return $out;
	}

	/**
	 * Returns visible trash rows, ordered (TRASHED_AT DESC, ID DESC).
	 * If $userId is null the caller is admin and no visibility filter is applied.
	 * Otherwise a row is visible when it satisfies any of:
	 *   - DOCUMENT.COLLECTION_ID is in $accessibleCollectionIds (a base the user can VIEW), OR
	 *   - the document has no knowledge base at all (orphan) AND user is TRASHED_BY or DOCUMENT.CREATED_BY.
	 * Authorship/trashedBy alone is NOT enough to expose a document whose base exists but
	 * the user has lost VIEW access to.
	 *
	 * An orphan is a document with no base whatsoever - and, since deleting a base removes its row
	 * outright, "no base" and "the base was deleted" are the same condition.
	 *
	 * Main documents are never listed: they are the description of a knowledge base and are
	 * destroyed along with it rather than trashed, so there is never one to restore.
	 *
	 * @param int[] $accessibleCollectionIds
	 * @param array{trashedAt: string, recycleBinId: int}|null $afterCursor
	 * @return array<int, array{id: int, trashedAt: string}>
	 */
	public function listVisible(
		?int $userId,
		array $accessibleCollectionIds,
		?array $afterCursor,
		int $limit,
	): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$query = RecycleBinTable::query()
			->setSelect(['ID', 'TRASHED_AT'])
			->where('DOCUMENT.IS_MAIN', DocumentTable::IS_MAIN_NO)
		;

		if ($userId !== null)
		{
			$visibility = Query::filter()->logic('or');

			if (!empty($accessibleCollectionIds))
			{
				$visibility->whereIn('DOCUMENT.COLLECTION_ID', $accessibleCollectionIds);
			}

			$orphanOwnership = Query::filter()
				->whereNull('DOCUMENT.COLLECTION.ID')
				->where(Query::filter()->logic('or')
					->where('TRASHED_BY', $userId)
					->where('DOCUMENT.CREATED_BY', $userId)
				)
			;
			$visibility->where($orphanOwnership);

			$query->where($visibility);
		}

		$cursorAt = CursorDate::parse($afterCursor['trashedAt'] ?? null);
		$cursorId = isset($afterCursor['recycleBinId']) ? (int)$afterCursor['recycleBinId'] : 0;
		if ($cursorAt !== null && $cursorId > 0)
		{
			$query->where(Query::filter()->logic('or')
				->where('TRASHED_AT', '<', $cursorAt)
				->where(Query::filter()
					->where('TRASHED_AT', $cursorAt)
					->where('ID', '<', $cursorId)
				)
			);
		}

		$query
			->addOrder('TRASHED_AT', 'DESC')
			->addOrder('ID', 'DESC')
			->setLimit($limit)
		;

		$rows = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$trashedAt = $row['TRASHED_AT'] ?? null;
			if ($trashedAt instanceof DateTime)
			{
				$trashedAtSql = $trashedAt->format('Y-m-d H:i:s');
			}
			else
			{
				$trashedAtSql = (string)($trashedAt ?? '');
			}
			$rows[] = [
				'id' => (int)$row['ID'],
				'trashedAt' => $trashedAtSql,
			];
		}

		return $rows;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Ddl\DbType;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Entity\RecycleBin\RecycleBinRecord;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\RecycleBinTable;
use Bitrix\Note\Internal\Repository\Mapper\RecycleBinRecordMapper;

class RecycleBinRepository
{
	/**
	 * Bulk INSERT IGNORE — on UNIQUE(DOCUMENT_ID) collision an already-trashed document is skipped
	 * without rolling back the batch.
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

		$chunks = array_chunk($records, 500);
		foreach ($chunks as $chunk)
		{
			$rows = [];
			foreach ($chunk as $record)
			{
				if (!$record instanceof RecycleBinRecord)
				{
					continue;
				}

				$row = RecycleBinRecordMapper::convertToOrm($record);
				$rows[] = sprintf(
					'(%d, %s, %d, %s)',
					$row['DOCUMENT_ID'],
					$helper->convertToDbDateTime($row['TRASHED_AT']),
					$row['TRASHED_BY'],
					"'" . $helper->forSql($row['ORIGIN'], 40) . "'",
				);
			}

			if (empty($rows))
			{
				continue;
			}

			$valuesClause = ' (DOCUMENT_ID, TRASHED_AT, TRASHED_BY, ORIGIN) VALUES ' . implode(',', $rows);
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
		}
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

	public function deleteById(int $recycleBinId): void
	{
		if ($recycleBinId <= 0)
		{
			return;
		}

		RecycleBinTable::delete($recycleBinId);
	}

	public function deleteByDocumentId(int $documentId): void
	{
		$this->deleteByDocumentIds([$documentId]);
	}

	/**
	 * @param int[] $documentIds
	 */
	public function deleteByDocumentIds(array $documentIds): void
	{
		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return;
		}

		$connection = Application::getConnection();
		$placeholders = implode(',', $normalized);
		$connection->queryExecute("DELETE FROM b_note_recycle_bin WHERE DOCUMENT_ID IN ({$placeholders})");
	}

	/**
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
	 * Returns visible trash rows, ordered (TRASHED_AT DESC, ID DESC).
	 * If $userId is null the caller is admin and no visibility filter is applied.
	 * Otherwise a row is visible when it satisfies any of:
	 *   - DOCUMENT.COLLECTION_ID is in $accessibleCollectionIds (live collection the user can VIEW), OR
	 *   - the source collection no longer exists (orphan) AND user is TRASHED_BY or DOCUMENT.CREATED_BY.
	 * Authorship/trashedBy alone is NOT enough to expose a document whose collection is alive but
	 * the user has lost VIEW access to.
	 *
	 * Main documents are never listed: they are collection description carriers with no place in
	 * the tree, so restoring one would hand back a document nothing can show. DeleteCollectionCommand
	 * hard-deletes them with the collection, and this predicate also hides the rows trashed before
	 * that (they expire through the TTL cleanup).
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

		$cursorAtRaw = is_string($afterCursor['trashedAt'] ?? null) ? $afterCursor['trashedAt'] : '';
		$cursorId = isset($afterCursor['recycleBinId']) ? (int)$afterCursor['recycleBinId'] : 0;
		if ($cursorAtRaw !== '' && $cursorId > 0)
		{
			$cursorAt = DateTime::createFromPhp(new \DateTime($cursorAtRaw));
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

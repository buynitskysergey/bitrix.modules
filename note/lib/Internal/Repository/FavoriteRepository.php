<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\FavoriteTable;

/**
 * [P1.T2] b_note_favorite access: idempotent add, remove, the user's position set for gap
 * math, the keyset page of one user's list and the hard-delete cascades.
 *
 * Favorites are personal, so every method takes $userId explicitly - the repository never
 * looks at the current user. Transactions belong to the calling command.
 */
class FavoriteRepository
{
	/**
	 * Adds one (userId, entityType, entityId) row via a cross-DB MERGE. A repeated add is a
	 * no-op: POSITION and CREATED_AT of an existing row survive, so the row keeps both its
	 * place in the manual order and its "favorited since" meaning. USER_ID is re-assigned its
	 * own value only because prepareMerge() returns empty SQL without an update part.
	 */
	public function add(int $userId, string $entityType, int $entityId, int $position, DateTime $createdAt): void
	{
		if ($userId <= 0 || $entityId <= 0)
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		[$sql] = $helper->prepareMerge(
			FavoriteTable::getTableName(),
			['USER_ID', 'ENTITY_TYPE', 'ENTITY_ID'],
			[
				'USER_ID' => $userId,
				'ENTITY_TYPE' => $entityType,
				'ENTITY_ID' => $entityId,
				'POSITION' => $position,
				'CREATED_AT' => $createdAt,
			],
			[
				'USER_ID' => $userId,
			],
		);

		if ($sql !== '')
		{
			$connection->queryExecute($sql);
		}
	}

	/**
	 * Drops the user's own row. No-op when it does not exist (remove is idempotent).
	 */
	public function remove(int $userId, string $entityType, int $entityId): void
	{
		if ($userId <= 0 || $entityId <= 0)
		{
			return;
		}

		FavoriteTable::deleteByFilter([
			'=USER_ID' => $userId,
			'=ENTITY_TYPE' => $entityType,
			'=ENTITY_ID' => $entityId,
		]);
	}

	/**
	 * @return array{id: int, position: int}|null
	 */
	public function findRow(int $userId, string $entityType, int $entityId): ?array
	{
		if ($userId <= 0 || $entityId <= 0)
		{
			return null;
		}

		$row = FavoriteTable::query()
			->setSelect(['ID', 'POSITION'])
			->where('USER_ID', $userId)
			->where('ENTITY_TYPE', $entityType)
			->where('ENTITY_ID', $entityId)
			->setLimit(1)
			->fetch()
		;

		if ($row === false)
		{
			return null;
		}

		return [
			'id' => (int)$row['ID'],
			'position' => (int)$row['POSITION'],
		];
	}

	/**
	 * The user's whole ordered position set - input for the gap math in FavoritePositionService.
	 * Order matches the list order, so index 0 is the topmost row.
	 *
	 * @return array<int, array{id: int, position: int}>
	 */
	public function getPositions(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$rows = FavoriteTable::query()
			->setSelect(['ID', 'POSITION'])
			->where('USER_ID', $userId)
			->addOrder('POSITION', 'DESC')
			->addOrder('ID', 'DESC')
			->fetchAll()
		;

		return array_map(static fn(array $row): array => [
			'id' => (int)$row['ID'],
			'position' => (int)$row['POSITION'],
		], $rows);
	}

	public function updatePosition(int $id, int $position): bool
	{
		if ($id <= 0)
		{
			return false;
		}

		return FavoriteTable::update($id, ['POSITION' => $position])->isSuccess();
	}

	/**
	 * One keyset window of the user's list, ordered (POSITION DESC, ID DESC) over
	 * IX_NOTE_FAV_PAGE. The cursor is the (position, id) pair of the last returned candidate.
	 *
	 * $visibility, when given, is the caller's ACL predicate applied right in the WHERE, so the
	 * window comes back already filtered and no row has to be dropped afterwards. It may reference
	 * the joined document through the DOCUMENT alias (see {@see joinDocument()}). Passing null
	 * means "no filtering" — the portal-admin path.
	 *
	 * @return array<int, array{id: int, entityType: string, entityId: int, position: int}>
	 */
	public function pageAfter(
		int $userId,
		?int $afterPosition,
		?int $afterId,
		int $limit,
		?ConditionTree $visibility = null,
	): array
	{
		if ($userId <= 0 || $limit <= 0)
		{
			return [];
		}

		$query = FavoriteTable::query()
			->setSelect(['ID', 'ENTITY_TYPE', 'ENTITY_ID', 'POSITION'])
			->where('USER_ID', $userId)
			->addOrder('POSITION', 'DESC')
			->addOrder('ID', 'DESC')
			->setLimit($limit)
		;

		if ($visibility !== null)
		{
			$this->joinDocument($query);
			$query->where($visibility);
		}

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

		return array_map(static fn(array $row): array => [
			'id' => (int)$row['ID'],
			'entityType' => (string)$row['ENTITY_TYPE'],
			'entityId' => (int)$row['ENTITY_ID'],
			'position' => (int)$row['POSITION'],
		], $query->fetchAll());
	}

	/**
	 * One favorite row of the user, but only if it passes the same visibility predicate a page
	 * would apply. Backs the single-row path (a freshly starred object), which must not answer a
	 * question the listing would answer differently — one predicate, both roads.
	 *
	 * @return array{id: int, entityType: string, entityId: int, position: int}|null
	 */
	public function findVisibleRow(
		int $userId,
		string $entityType,
		int $entityId,
		?ConditionTree $visibility = null,
	): ?array
	{
		if ($userId <= 0 || $entityId <= 0)
		{
			return null;
		}

		$query = FavoriteTable::query()
			->setSelect(['ID', 'ENTITY_TYPE', 'ENTITY_ID', 'POSITION'])
			->where('USER_ID', $userId)
			->where('ENTITY_TYPE', $entityType)
			->where('ENTITY_ID', $entityId)
			->setLimit(1)
		;

		if ($visibility !== null)
		{
			$this->joinDocument($query);
			$query->where($visibility);
		}

		$row = $query->fetch();
		if ($row === false)
		{
			return null;
		}

		return [
			'id' => (int)$row['ID'],
			'entityType' => (string)$row['ENTITY_TYPE'],
			'entityId' => (int)$row['ENTITY_ID'],
			'position' => (int)$row['POSITION'],
		];
	}

	/**
	 * Attaches the document behind a 'document' row as the DOCUMENT alias, so a visibility
	 * predicate can read its COLLECTION_ID, IS_MAIN and recycle-bin state as ordinary columns.
	 *
	 * A LEFT JOIN with the type test inside the ON, rather than a sub-query on ENTITY_ID: the
	 * favorites table is mixed, and on MySQL 5.6 a sub-query carrying its own join is rewritten
	 * into a dependent one and re-run per row (ADR section 5). Collection rows simply join to
	 * nothing, which the predicate's ENTITY_TYPE branches already account for.
	 */
	private function joinDocument(Query $query): void
	{
		$query->registerRuntimeField(
			new Reference(
				'DOCUMENT',
				DocumentTable::class,
				Join::on('this.ENTITY_ID', 'ref.ID')
					->where('this.ENTITY_TYPE', FavoriteTable::ENTITY_TYPE_DOCUMENT),
				['join_type' => 'LEFT'],
			),
		);
	}

	/**
	 * Subset of $entityIds the user has in favorites - batch source of the star flag in the
	 * existing tree/document responses.
	 *
	 * @param int[] $entityIds
	 * @return int[]
	 */
	public function findFavoriteEntityIds(int $userId, string $entityType, array $entityIds): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$normalized = $this->normalizeIds($entityIds);
		if (empty($normalized))
		{
			return [];
		}

		$ids = [];
		$result = FavoriteTable::query()
			->setSelect(['ENTITY_ID'])
			->where('USER_ID', $userId)
			->where('ENTITY_TYPE', $entityType)
			->whereIn('ENTITY_ID', $normalized)
			->exec()
		;
		while ($row = $result->fetch())
		{
			$ids[(int)$row['ENTITY_ID']] = true;
		}

		return array_keys($ids);
	}

	/**
	 * Cascade for document hard-delete: drops every user's favorite row targeting the removed
	 * documents. Caller's transaction (per MODULE.md).
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

		FavoriteTable::deleteByFilter([
			'=ENTITY_TYPE' => FavoriteTable::ENTITY_TYPE_DOCUMENT,
			'@ENTITY_ID' => $normalized,
		]);
	}

	/**
	 * Cascade for collection hard-delete: drops every user's favorite row targeting the
	 * removed collection.
	 */
	public function deleteByCollectionId(int $collectionId): void
	{
		if ($collectionId <= 0)
		{
			return;
		}

		FavoriteTable::deleteByFilter([
			'=ENTITY_TYPE' => FavoriteTable::ENTITY_TYPE_COLLECTION,
			'=ENTITY_ID' => $collectionId,
		]);
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
}

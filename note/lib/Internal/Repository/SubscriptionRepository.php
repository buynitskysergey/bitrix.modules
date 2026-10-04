<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\SubscriptionTable;

/**
 * [P6.T1] b_note_subscription access: upsert/remove on the colocated bell
 * control, subscriber lookups for SubscriptionResolver (P6.T2), and hard-delete
 * cascades.
 */
class SubscriptionRepository
{
	/**
	 * Upsert one (userId, scope, entityId) subscription row via a cross-DB MERGE —
	 * only MODE is refreshed on an existing row (switching self <-> subtree just
	 * updates the mode instead of adding a second row); CREATED_AT is set once on
	 * insert and left untouched afterwards, so it keeps meaning "subscribed
	 * since", not "last touched".
	 */
	public function upsert(int $userId, string $scope, int $entityId, string $mode, DateTime $createdAt): void
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		[$sql] = $helper->prepareMerge(
			SubscriptionTable::getTableName(),
			['USER_ID', 'SCOPE', 'ENTITY_ID'],
			[
				'USER_ID' => $userId,
				'SCOPE' => $scope,
				'ENTITY_ID' => $entityId,
				'MODE' => $mode,
				'CREATED_AT' => $createdAt,
			],
			[
				'MODE' => $mode,
			],
		);

		if ($sql !== '')
		{
			$connection->queryExecute($sql);
		}
	}

	/**
	 * Unsubscribe — deletes the caller's own subscription row. No-op if it
	 * does not exist (remove is idempotent).
	 */
	public function remove(int $userId, string $scope, int $entityId): void
	{
		SubscriptionTable::deleteByFilter([
			'=USER_ID' => $userId,
			'=SCOPE' => $scope,
			'=ENTITY_ID' => $entityId,
		]);
	}

	/**
	 * Deletes the caller's own row only if its MODE is one of $modes, and reports whether such a row
	 * was there. The mode goes into the DELETE filter instead of a preceding read, so a concurrent
	 * mode change between "which mode is it" and "delete it" can neither drop the wrong row nor miss
	 * the right one.
	 *
	 * @param string[] $modes
	 */
	public function removeByModes(int $userId, string $scope, int $entityId, array $modes): bool
	{
		if (empty($modes))
		{
			return false;
		}

		SubscriptionTable::deleteByFilter([
			'=USER_ID' => $userId,
			'=SCOPE' => $scope,
			'=ENTITY_ID' => $entityId,
			'@MODE' => $modes,
		]);

		return Application::getConnection()->getAffectedRowsCount() > 0;
	}

	/**
	 * @return array{mode: string}|null
	 */
	public function getUserState(int $userId, string $scope, int $entityId): ?array
	{
		if ($userId <= 0 || $entityId <= 0)
		{
			return null;
		}

		$row = SubscriptionTable::query()
			->setSelect(['MODE'])
			->where('USER_ID', $userId)
			->where('SCOPE', $scope)
			->where('ENTITY_ID', $entityId)
			->setLimit(1)
			->fetch()
		;

		if ($row === false)
		{
			return null;
		}

		return ['mode' => (string)$row['MODE']];
	}

	/**
	 * Distinct subscriber user ids for a set of targets, optionally narrowed to one
	 * MODE — used by SubscriptionResolver to separate "direct" subscribers (any
	 * mode) from "ancestor, subtree-only" subscribers.
	 *
	 * @param int[] $entityIds
	 * @return int[]
	 */
	public function findSubscriberUserIds(string $scope, array $entityIds, ?string $mode = null): array
	{
		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $entityIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return [];
		}

		$query = SubscriptionTable::query()
			->setSelect(['USER_ID'])
			->where('SCOPE', $scope)
			->whereIn('ENTITY_ID', $normalized)
		;

		if ($mode !== null)
		{
			$query->where('MODE', $mode);
		}

		$userIds = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$userIds[(int)$row['USER_ID']] = true;
		}

		return array_keys($userIds);
	}

	/**
	 * True if the user has at least one subscription row matching (scope, any of $entityIds[, mode]).
	 * Used by the bell (SubscriptionProvider) to detect inherited coverage — a subtree subscription
	 * on an ancestor — without pulling the full subscriber set.
	 *
	 * @param int[] $entityIds
	 */
	public function userHasSubscription(int $userId, string $scope, array $entityIds, ?string $mode = null): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $entityIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return false;
		}

		$query = SubscriptionTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->where('SCOPE', $scope)
			->whereIn('ENTITY_ID', $normalized)
			->setLimit(1)
		;

		if ($mode !== null)
		{
			$query->where('MODE', $mode);
		}

		return $query->fetch() !== false;
	}

	/**
	 * Subset of $entityIds the user is subscribed to (scope[, mode]) — same predicate as
	 * userHasSubscription, but returns which targets matched instead of a bare bool. The bell uses it
	 * to name the *nearest* subtree-subscribed ancestor for the "inherited" info line (intersect the
	 * result with the nearest-first ancestor list).
	 *
	 * @param int[] $entityIds
	 * @return int[]
	 */
	public function findSubscribedEntityIds(int $userId, string $scope, array $entityIds, ?string $mode = null): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $entityIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return [];
		}

		$query = SubscriptionTable::query()
			->setSelect(['ENTITY_ID'])
			->where('USER_ID', $userId)
			->where('SCOPE', $scope)
			->whereIn('ENTITY_ID', $normalized)
		;

		if ($mode !== null)
		{
			$query->where('MODE', $mode);
		}

		$ids = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$ids[(int)$row['ENTITY_ID']] = true;
		}

		return array_keys($ids);
	}

	/**
	 * [P2.T2] Modes of the user's own direct rows for a set of targets - findSubscribedEntityIds()
	 * answers "which of these" but drops the MODE, and the coverage resolver needs the mode itself
	 * (muted is a row like any other, it just means the opposite).
	 *
	 * @param int[] $entityIds
	 * @return array<int, string> entityId => MODE, only for targets that have a row
	 */
	public function getUserModes(int $userId, string $scope, array $entityIds): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $entityIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return [];
		}

		$modes = [];
		$result = SubscriptionTable::query()
			->setSelect(['ENTITY_ID', 'MODE'])
			->where('USER_ID', $userId)
			->where('SCOPE', $scope)
			->whereIn('ENTITY_ID', $normalized)
			->exec()
		;
		while ($row = $result->fetch())
		{
			$modes[(int)$row['ENTITY_ID']] = (string)$row['MODE'];
		}

		return $modes;
	}

	/**
	 * [P2.T2] Every document the user subscribed to "with nested" - the anchors a subtree
	 * coverage walk can hit. Read without an input set (one query on (USER_ID, SCOPE, MODE)),
	 * so the coverage resolver can skip the ancestor walk entirely when the set is empty.
	 *
	 * @return int[]
	 */
	public function findUserSubtreeAnchorIds(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$ids = [];
		$result = SubscriptionTable::query()
			->setSelect(['ENTITY_ID'])
			->where('USER_ID', $userId)
			->where('SCOPE', SubscriptionTable::SCOPE_DOCUMENT)
			->where('MODE', SubscriptionTable::MODE_SUBTREE)
			->exec()
		;
		while ($row = $result->fetch())
		{
			$ids[(int)$row['ENTITY_ID']] = true;
		}

		return array_keys($ids);
	}

	/**
	 * Document ids the user has muted (SCOPE=document, MODE=muted). Small set in practice — the mute
	 * pruner iterates it after an unsubscribe to drop mutes that no longer cover anything.
	 *
	 * @return int[]
	 */
	public function findMutedDocumentIds(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$ids = [];
		$result = SubscriptionTable::query()
			->setSelect(['ENTITY_ID'])
			->where('USER_ID', $userId)
			->where('SCOPE', SubscriptionTable::SCOPE_DOCUMENT)
			->where('MODE', SubscriptionTable::MODE_MUTED)
			->exec()
		;
		while ($row = $result->fetch())
		{
			$ids[(int)$row['ENTITY_ID']] = true;
		}

		return array_keys($ids);
	}

	/**
	 * Drops the user's mute rows on the given documents. Used by the mute pruner — a mute is a negative
	 * override on inherited coverage, so once that coverage is gone the row is dead weight (and would
	 * silently re-suppress the document if the user ever re-subscribes to the covering scope).
	 *
	 * @param int[] $documentIds
	 */
	public function removeMutes(int $userId, array $documentIds): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return;
		}

		SubscriptionTable::deleteByFilter([
			'=USER_ID' => $userId,
			'=SCOPE' => SubscriptionTable::SCOPE_DOCUMENT,
			'=MODE' => SubscriptionTable::MODE_MUTED,
			'@ENTITY_ID' => $normalized,
		]);
	}

	/**
	 * Drops the user's now-redundant per-document self/subtree subscriptions inside a collection —
	 * called when they subscribe to the whole collection (SCOPE=collection, MODE=all), which already
	 * covers every document in it. Mutes (negative overrides) are kept: those are explicit "don't
	 * notify me here" choices the collection subscription must not undo. Subquery is on a different
	 * table (documents), so the DELETE is valid on both MySQL and PostgreSQL.
	 */
	public function removeRedundantDocumentSubscriptions(int $userId, int $collectionId): void
	{
		if ($userId <= 0 || $collectionId <= 0)
		{
			return;
		}

		$connection = Application::getConnection();
		$subscriptionTable = SubscriptionTable::getTableName();
		$documentTable = DocumentTable::getTableName();
		// SCOPE/MODE are fixed enum literals; ids are cast to int — no user-controlled SQL here.
		$scope = SubscriptionTable::SCOPE_DOCUMENT;
		$self = SubscriptionTable::MODE_SELF;
		$subtree = SubscriptionTable::MODE_SUBTREE;

		$connection->queryExecute(
			"DELETE FROM {$subscriptionTable}"
			. " WHERE USER_ID = {$userId}"
			. " AND SCOPE = '{$scope}'"
			. " AND MODE IN ('{$self}', '{$subtree}')"
			. " AND ENTITY_ID IN (SELECT ID FROM {$documentTable} WHERE COLLECTION_ID = {$collectionId})",
		);
	}

	/**
	 * Cascade for document hard-delete: drops every subscription targeting the
	 * removed documents (SCOPE=document). Caller's transaction (per MODULE.md).
	 *
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

		SubscriptionTable::deleteByFilter([
			'=SCOPE' => SubscriptionTable::SCOPE_DOCUMENT,
			'@ENTITY_ID' => $normalized,
		]);
	}

	/**
	 * Cascade for collection hard-delete: drops every subscription targeting the
	 * removed collection (SCOPE=collection).
	 */
	public function deleteByCollectionId(int $collectionId): void
	{
		if ($collectionId <= 0)
		{
			return;
		}

		SubscriptionTable::deleteByFilter([
			'=SCOPE' => SubscriptionTable::SCOPE_COLLECTION,
			'=ENTITY_ID' => $collectionId,
		]);
	}
}

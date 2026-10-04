<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Model\DocumentLinkTable;
use Bitrix\Note\Internal\Model\DocumentTable;

/**
 * [P1.T2] b_note_document_link access: the current target set of one source, the whole-set
 * rewrite after a save, the keyset page of backlinks and the hard-delete cascades.
 *
 * Transactions belong to the calling command: replaceTargets() deletes and inserts as two
 * statements and is only correct inside an already open transaction.
 */
class DocumentLinkRepository
{
	// [B3] Cap on rows per addMulti INSERT: a source may legitimately carry up to
	// Configuration::MAX_DOCUMENT_LINK_TARGETS links, too many for a single statement.
	private const INSERT_CHUNK_SIZE = 500;

	/**
	 * @return int[]
	 */
	public function getTargetIds(int $sourceId): array
	{
		if ($sourceId <= 0)
		{
			return [];
		}

		$rows = DocumentLinkTable::query()
			->setSelect(['TARGET_ID'])
			->where('SOURCE_ID', $sourceId)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['TARGET_ID'], $rows);
	}

	/**
	 * Rewrites the source's whole target set: the old rows go, the new set is inserted as one
	 * multi-row statement. A whole-set rewrite (instead of a diff) keeps the index in step with the
	 * content even when the previous save was interrupted.
	 *
	 * @param int[] $targetIds
	 */
	public function replaceTargets(int $sourceId, array $targetIds): void
	{
		if ($sourceId <= 0)
		{
			return;
		}

		DocumentLinkTable::deleteByFilter(['=SOURCE_ID' => $sourceId]);

		$normalized = $this->normalizeIds($targetIds);
		if (empty($normalized))
		{
			return;
		}

		$createdAt = new DateTime();
		$rows = array_map(static fn(int $targetId): array => [
			'SOURCE_ID' => $sourceId,
			'TARGET_ID' => $targetId,
			'CREATED_AT' => $createdAt,
		], $normalized);

		foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk)
		{
			DocumentLinkTable::addMulti($chunk, true);
		}
	}

	/**
	 * [C2-perf] Current target set of several sources in one query. Used by the one-time backfill so a
	 * chunk of sources costs one SELECT instead of one per source.
	 *
	 * @param int[] $sourceIds
	 * @return array<int, int[]> sourceId => target ids (every requested source present, [] when none)
	 */
	public function getTargetIdsBySources(array $sourceIds): array
	{
		$normalized = $this->normalizeIds($sourceIds);
		if (empty($normalized))
		{
			return [];
		}

		$map = array_fill_keys($normalized, []);
		$rows = DocumentLinkTable::query()
			->setSelect(['SOURCE_ID', 'TARGET_ID'])
			->whereIn('SOURCE_ID', $normalized)
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$map[(int)$row['SOURCE_ID']][] = (int)$row['TARGET_ID'];
		}

		return $map;
	}

	/**
	 * Deduplicated union of every target the given sources point at. Feeds the fan-out that tells
	 * those targets their incoming set changed (hard delete, source metadata change).
	 *
	 * @param int[] $sourceIds
	 * @return int[]
	 */
	public function collectTargetIdsBySources(array $sourceIds): array
	{
		$union = [];
		foreach ($this->getTargetIdsBySources($sourceIds) as $targetIds)
		{
			foreach ($targetIds as $targetId)
			{
				$union[$targetId] = true;
			}
		}

		return array_keys($union);
	}

	/**
	 * [C3-perf] Drops the whole outgoing set of the given sources in one statement. Used by hard
	 * delete, where the sources are about to be physically removed and their index rows must go
	 * unconditionally — there is no later save to repair a leftover row.
	 *
	 * @param int[] $sourceIds
	 */
	public function deleteBySourceIds(array $sourceIds): void
	{
		$normalized = $this->normalizeIds($sourceIds);
		if (empty($normalized))
		{
			return;
		}

		DocumentLinkTable::deleteByFilter(['@SOURCE_ID' => $normalized]);
	}

	/**
	 * Cascade for target hard-delete: incoming links of the removed documents. Sources keep their
	 * text as it was written, only the index entry goes.
	 *
	 * @param int[] $targetIds
	 */
	public function deleteByTargetIds(array $targetIds): void
	{
		$normalized = $this->normalizeIds($targetIds);
		if (empty($normalized))
		{
			return;
		}

		DocumentLinkTable::deleteByFilter(['@TARGET_ID' => $normalized]);
	}

	/**
	 * One keyset window of the target's backlinks, most-recently-created source first. Reads
	 * IX_NOTE_DOCLINK_TARGET and joins b_note_document for the title and timestamp; archived and
	 * trashed sources are cut in the query, the permission check stays with the caller.
	 *
	 * [A4] The keyset walks SOURCE_ID DESC, exactly the (TARGET_ID, SOURCE_ID) index — the joined
	 * SOURCE.UPDATED_AT that used to drive the order is covered by no index, so it sorted the target's
	 * whole backlink set before the limit on every list request. UPDATED_AT is now display only.
	 * [A3] The cursor is a plain SOURCE_ID; the caller builds it from the last VISIBLE row so a
	 * permission-hidden source is never exposed through it.
	 *
	 * The reported UPDATED_AT is the source's content date: the date a user sees must move when the
	 * text changes and stay put when the source is merely moved, renamed or archived. Taking the
	 * later of the two dates would let those structural operations push it forward again. COALESCE
	 * covers legacy rows, whose CONTENT_UPDATED_AT is still NULL (no backfill), and falls back to
	 * UPDATED_AT for them. The expression sits in the SELECT only: order and cursor stay on
	 * SOURCE_ID, so no index is given up for it.
	 *
	 * A collection main document IS reported, flagged by IS_MAIN: a link written in a knowledge base
	 * description is a real incoming link, and hiding it left the row in the index with no way to
	 * ever see it. The caller renders such a source as the base itself.
	 *
	 * $limit + 1 rows are fetched so a full page is told apart from the end of the list.
	 *
	 * $visibility, when given, is the caller's ACL predicate applied right in the WHERE — the page
	 * then comes back already filtered, so there is no window loop and no post-filter. Passing null
	 * means "no filtering", which is the portal-admin path.
	 *
	 * @return array{rows: array<int, array{SOURCE_ID: int, COLLECTION_ID: int, TITLE: string, UPDATED_AT: DateTime, IS_ARCHIVED: string, IS_MAIN: string}>, hasNextPage: bool}
	 */
	public function listSources(
		int $targetId,
		int $limit,
		?int $afterSourceId = null,
		?ConditionTree $visibility = null,
	): array
	{
		if ($targetId <= 0 || $limit <= 0)
		{
			return ['rows' => [], 'hasNextPage' => false];
		}

		$query = DocumentLinkTable::query()
			->registerRuntimeField(
				new ExpressionField(
					'SOURCE_CONTENT_DATE',
					'COALESCE(%s, %s)',
					['SOURCE.CONTENT_UPDATED_AT', 'SOURCE.UPDATED_AT'],
				),
			)
			->setSelect([
				'SOURCE_ID',
				'COLLECTION_ID' => 'SOURCE.COLLECTION_ID',
				'TITLE' => 'SOURCE.TITLE',
				'UPDATED_AT' => 'SOURCE_CONTENT_DATE',
				'IS_ARCHIVED' => 'SOURCE.IS_ARCHIVED',
				'IS_MAIN' => 'SOURCE.IS_MAIN',
			])
			->where('TARGET_ID', $targetId)
			->where('SOURCE.IS_ARCHIVED', 'N')
			// Excluding join: a source sitting in the recycle bin has no place in the list.
			->whereNull('SOURCE.RECYCLE_BIN.ID')
			->addOrder('SOURCE_ID', 'DESC')
			->setLimit($limit + 1)
		;

		if ($visibility !== null)
		{
			$query->where($visibility);
		}

		if ($afterSourceId !== null && $afterSourceId > 0)
		{
			$query->where('SOURCE_ID', '<', $afterSourceId);
		}

		$rows = $query->fetchAll();

		$hasNextPage = count($rows) > $limit;
		if ($hasNextPage)
		{
			$rows = array_slice($rows, 0, $limit);
		}

		// Whether a datetime value arrives already converted depends on the connection, and an
		// expression is likelier to come back raw than a plain column: normalize it once here.
		foreach ($rows as &$row)
		{
			if (!($row['UPDATED_AT'] instanceof DateTime))
			{
				$raw = (string)($row['UPDATED_AT'] ?? '');
				$row['UPDATED_AT'] = $raw === ''
					? new DateTime()
					: DateTime::createFromPhp(new \DateTime($raw))
				;
			}
		}
		unset($row);

		return ['rows' => $rows, 'hasNextPage' => $hasNextPage];
	}

	/**
	 * Counting window: the same rows listSources() would report, but only the two columns the
	 * permission filter needs, ordered by the link's own SOURCE_ID.
	 *
	 * Counting does not care about order, and that matters: listSources() used to order by the joined
	 * document's UPDATED_AT, which no index covers, so MySQL sorted the target's whole backlink set
	 * before the limit. The counter runs on every document open (the bootstrap slice), which made
	 * that sort the price of opening any document. (TARGET_ID, SOURCE_ID) is exactly
	 * IX_NOTE_DOCLINK_TARGET, so this window is read straight off the index - and the keyset walks
	 * SOURCE_ID alone for the same reason.
	 *
	 * @return array{rows: array<int, array{SOURCE_ID: int, COLLECTION_ID: int}>, hasNextPage: bool}
	 */
	public function listSourcesForCount(
		int $targetId,
		int $limit,
		?int $afterSourceId = null,
		?ConditionTree $visibility = null,
	): array
	{
		if ($targetId <= 0 || $limit <= 0)
		{
			return ['rows' => [], 'hasNextPage' => false];
		}

		$query = DocumentLinkTable::query()
			->setSelect(['SOURCE_ID', 'COLLECTION_ID' => 'SOURCE.COLLECTION_ID'])
			->where('TARGET_ID', $targetId)
			->where('SOURCE.IS_ARCHIVED', 'N')
			->whereNull('SOURCE.RECYCLE_BIN.ID')
			->addOrder('SOURCE_ID', 'DESC')
			->setLimit($limit + 1)
		;

		if ($visibility !== null)
		{
			$query->where($visibility);
		}

		if ($afterSourceId !== null && $afterSourceId > 0)
		{
			$query->where('SOURCE_ID', '<', $afterSourceId);
		}

		$rows = $query->fetchAll();

		$hasNextPage = count($rows) > $limit;

		return [
			'rows' => $hasNextPage ? array_slice($rows, 0, $limit) : $rows,
			'hasNextPage' => $hasNextPage,
		];
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

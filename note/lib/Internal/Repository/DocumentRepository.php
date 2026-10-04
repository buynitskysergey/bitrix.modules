<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\Result as OrmResult;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Note\Internal\Model\CollectionTable;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Service\Document\MainDocumentFilter;
use Bitrix\Note\Internal\Service\RecycleBin\RecycleBinFilter;

class DocumentRepository
{
	private const ORM_CACHE_TTL = 15;

	private ?RecycleBinFilter $recycleBinFilter = null;

	private ?MainDocumentFilter $mainDocumentFilter = null;

	private function recycleBinFilter(): RecycleBinFilter
	{
		return $this->recycleBinFilter ??= new RecycleBinFilter();
	}

	private function mainDocumentFilter(): MainDocumentFilter
	{
		return $this->mainDocumentFilter ??= new MainDocumentFilter();
	}

	private const LIST_SELECT = [
		'ID', 'COLLECTION_ID', 'PARENT_ID', 'TITLE',
		'POSITION', 'IS_ARCHIVED', 'CREATED_BY', 'UPDATED_BY',
		'CREATED_AT', 'UPDATED_AT', 'CONTENT_UPDATED_AT',
	];

	private const TREE_SELECT = [
		'ID', 'COLLECTION_ID', 'PARENT_ID', 'TITLE', 'POSITION', 'IS_ARCHIVED',
	];

	private const ANCESTOR_SELECT = ['ID', 'PARENT_ID'];

	/**
	 * Content fields the collaboration bootstrap rebuilds the editor from, see getCollaborationSnapshot().
	 * MATERIALIZED_UPTO_ID rides along for CollaborationEligibility - the same uncached row, no extra query.
	 */
	private const COLLABORATION_SELECT = [
		'ID', 'COLLECTION_ID', 'CONTENT_FORMAT', 'MARKDOWN', 'YJS_STATE', 'MATERIALIZED_UPTO_ID',
	];

	public function save(Document $document): Result
	{
		$now = new DateTime();
		$document->setUpdatedAt($now);
		if ($document->getId() === null)
		{
			$document->setCreatedAt($now);
		}

		$saveResult = $document->save();

		if (!$saveResult->isSuccess())
		{
			return $this->buildFailedSaveResult($saveResult);
		}

		$result = new Result();
		$result->setData(['document' => $document]);

		return $result;
	}

	public function getById(int $id): ?Document
	{
		return DocumentTable::getByPrimary($id, ['cache' => ['ttl' => self::ORM_CACHE_TTL]])->fetchObject();
	}

	/**
	 * Uncached source location (COLLECTION_ID/PARENT_ID). Used for the pre-move snapshot so the move
	 * domain event captures the true "from" location, unaffected by the getById() ORM cache.
	 *
	 * @return array{collectionId: int, parentId: ?int}|null
	 */
	public function getLocationById(int $id): ?array
	{
		$row = DocumentTable::query()
			->setSelect(['COLLECTION_ID', 'PARENT_ID'])
			->where('ID', $id)
			->fetch()
		;
		if ($row === false)
		{
			return null;
		}

		return [
			'collectionId' => (int)$row['COLLECTION_ID'],
			'parentId' => $row['PARENT_ID'] !== null ? (int)$row['PARENT_ID'] : null,
		];
	}

	/**
	 * Uncached content snapshot for the collaboration bootstrap. Deliberately not getById(): that one is
	 * served from the ORM cache, and compaction writes YJS_STATE and cuts the journal in a single
	 * transaction. A cached snapshot from before that commit, paired with a journal read after it, hands
	 * the client a state that is missing exactly the window the compaction folded away - and the client
	 * rebuilds itself from this response, so it would then persist that hole as the settled state.
	 */
	public function getCollaborationSnapshot(int $id): ?Document
	{
		return $this->getMetaById($id, self::COLLABORATION_SELECT, useCache: false);
	}

	public function getYjsState(int $id): ?string
	{
		$row = DocumentTable::query()
			->setSelect(['YJS_STATE'])
			->where('ID', $id)
			->fetch()
		;

		if ($row && $row['YJS_STATE'] !== null && $row['YJS_STATE'] !== '')
		{
			return (string)$row['YJS_STATE'];
		}

		return null;
	}

	/**
	 * Uncached current TITLE. Used to detect a real title change on update without the getById() ORM
	 * cache — avoids both a spurious `titleChanged` event and a missed one on a stale cache.
	 */
	public function getTitleById(int $id): ?string
	{
		$row = DocumentTable::query()
			->setSelect(['TITLE'])
			->where('ID', $id)
			->fetch()
		;
		if ($row === false)
		{
			return null;
		}

		return $row['TITLE'] !== null ? (string)$row['TITLE'] : '';
	}

	public function getRawMarkdown(int $id): ?string
	{
		$row = DocumentTable::query()
			->setSelect(['MARKDOWN'])
			->where('ID', $id)
			->fetch()
		;

		if ($row === false)
		{
			return null;
		}

		$value = $row['MARKDOWN'] ?? null;

		return $value === null ? '' : (string)$value;
	}

	public function getMetaById(int $id, array $select = self::LIST_SELECT, bool $useCache = true): ?Document
	{
		$query = DocumentTable::query()
			->setSelect($select)
			->where('ID', $id)
		;
		if ($useCache)
		{
			$query->setCacheTtl(self::ORM_CACHE_TTL);
		}

		return $query->fetchObject();
	}

	/**
	 * @param int[] $ids
	 * @return Document[] preserving the order of $ids
	 */
	public function getMetaByIds(array $ids, array $select = self::LIST_SELECT): array
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));

		if (empty($normalizedIds))
		{
			return [];
		}

		$documents = DocumentTable::query()
			->setSelect($select)
			->whereIn('ID', $normalizedIds)
			->fetchCollection()
			->getAll()
		;

		$byId = [];
		foreach ($documents as $document)
		{
			$byId[(int)$document->getId()] = $document;
		}

		$ordered = [];
		foreach ($normalizedIds as $id)
		{
			if (isset($byId[$id]))
			{
				$ordered[] = $byId[$id];
			}
		}

		return $ordered;
	}

	/**
	 * Batch SELECT by primary key. Returns map [id => row].
	 *
	 * @param int[] $ids
	 * @param string[] $select
	 * @return array<int, array<string, mixed>>
	 */
	public function getByIds(array $ids, array $select = self::LIST_SELECT): array
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalizedIds))
		{
			return [];
		}

		$rows = DocumentTable::getList([
			'select' => $select,
			'filter' => ['=ID' => $normalizedIds],
		])->fetchAll();

		$map = [];
		foreach ($rows as $row)
		{
			$map[(int)$row['ID']] = $row;
		}

		return $map;
	}

	public function updatePartial(int $id, array $fields): void
	{
		if (empty($fields))
		{
			return;
		}

		DocumentTable::update($id, $fields);
	}

	/**
	 * [ALG-01] Shared forward-only projection write used by both materialization and compaction. Runs
	 * under an already-held 'compact' lock (this method does not take the lock itself). Reads the current
	 * cursor uncached: if the incoming cursor is not ahead of it, a stale writer is trying to overwrite a
	 * fresher projection — leave MARKDOWN/date/cursor untouched and return false (no rollback). Otherwise
	 * write the projection, content date, cursor and raise the derived-stale flag in a single partial
	 * update (never save(): UPDATED_AT must not move), and return true. Derived projections (search, links)
	 * are recomputed later by the freshness agent, hence IS_DERIVED_STALE='Y'.
	 */
	public function materializeProjection(int $id, string $markdown, int $uptoId): bool
	{
		$row = DocumentTable::query()
			->setSelect(['MATERIALIZED_UPTO_ID'])
			->where('ID', $id)
			->fetch()
		;
		if ($row === false)
		{
			return false;
		}

		$stored = $row['MATERIALIZED_UPTO_ID'];
		if ($stored !== null && $uptoId <= (int)$stored)
		{
			return false;
		}

		$this->updatePartial($id, [
			'MARKDOWN' => $markdown,
			'CONTENT_UPDATED_AT' => new DateTime(),
			'MATERIALIZED_UPTO_ID' => $uptoId,
			'IS_DERIVED_STALE' => DocumentTable::DERIVED_STALE_YES,
		]);

		return true;
	}

	/**
	 * Whether the document holds any text at all, read uncached and without reading the text itself.
	 * Asked by the one caller that needs the answer and not the value (SaveYjsStateCommand, deciding
	 * whether a baseline could have been rebuilt from it): selecting MARKDOWN there would pull the whole
	 * document through a cached query, and a cached answer is the wrong one - the rest of that rule reads
	 * uncached precisely because a stale value reopens the hole it closes.
	 */
	public function hasMarkdown(int $id): bool
	{
		$row = DocumentTable::query()
			->setSelect(['MARKDOWN_LENGTH'])
			->where('ID', $id)
			->registerRuntimeField(
				new ExpressionField('MARKDOWN_LENGTH', 'CHAR_LENGTH(%s)', ['MARKDOWN']),
			)
			->fetch()
		;

		return $row !== false && (int)$row['MARKDOWN_LENGTH'] > 0;
	}

	/**
	 * [ALG-01] Current materialization cursor, read uncached. NULL means the document has never been
	 * materialized. Callers that need it as a lower bound treat NULL as 0.
	 */
	public function getMaterializedUptoId(int $id): ?int
	{
		$row = DocumentTable::query()
			->setSelect(['MATERIALIZED_UPTO_ID'])
			->where('ID', $id)
			->fetch()
		;
		if ($row === false || $row['MATERIALIZED_UPTO_ID'] === null)
		{
			return null;
		}

		return (int)$row['MATERIALIZED_UPTO_ID'];
	}

	/**
	 * [ALG-01] Ceiling for a client-supplied materialization cursor, shared by materialization and
	 * compaction so the two entry points cannot drift apart. Nothing but the client vouches for the
	 * cursor: left unclamped, one inflated value lands in MATERIALIZED_UPTO_ID above every real patch id,
	 * and the forward-only guard then refuses every later projection write, freezing MARKDOWN for good.
	 * Clamping instead of rejecting keeps a legitimately racing client working. Compaction must pass the
	 * journal head it read for the drain, so the cursor it persists never claims more than the patches it
	 * actually merged.
	 *
	 * Takes a cursor from outside and pushes it DOWN into the allowed range; a caller with no cursor of
	 * its own wants {@see pinMaterializationCursor()} instead. The two must not be swapped: this one can
	 * only ever return something the caller asked for, the other always returns the top of the range.
	 */
	public static function clampMaterializationCursor(int $uptoId, ?int $journalLastId, ?int $storedUptoId): int
	{
		return max(0, min($uptoId, self::materializationCeiling($journalLastId, $storedUptoId)));
	}

	/**
	 * [ALG-01] The cursor for a writer that replaced the whole text rather than continuing it - overwrite,
	 * import, restore of a version. There is nothing to clamp: every patch still in the journal describes
	 * text that no longer exists, so all of them count as accounted for and the cursor goes straight to the
	 * top of the range. That is what makes a late materialize from a tab unaware of the overwrite land
	 * below the watermark, where the forward-only guard drops it.
	 *
	 * Deliberately NOT a clamp of the journal head, although the value coincides: a clamp answers "how much
	 * of what you claim may I believe", this answers "everything so far is settled, regardless of what
	 * anyone claims".
	 */
	public static function pinMaterializationCursor(?int $journalLastId, ?int $storedUptoId): int
	{
		return self::materializationCeiling($journalLastId, $storedUptoId);
	}

	/**
	 * Highest cursor value that corresponds to a real patch: the journal head, or, right after a
	 * compaction drained the journal and there is no head left, the stored cursor.
	 */
	private static function materializationCeiling(?int $journalLastId, ?int $storedUptoId): int
	{
		return max(0, (int)$journalLastId, (int)$storedUptoId);
	}

	/**
	 * [ALG-02] Dirty queue for the freshness agent: ids of documents whose derived projections (search,
	 * links) need a rebuild, in ID order after $afterId, served by IX_NOTE_DOC_DERIVED_STALE. The flag
	 * itself is the queue — there is no persistent cursor.
	 *
	 * @return int[]
	 */
	public function listStaleDerived(int $afterId, int $limit): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$rows = DocumentTable::query()
			->setSelect(['ID'])
			->where('IS_DERIVED_STALE', DocumentTable::DERIVED_STALE_YES)
			->where('ID', '>', $afterId)
			->addOrder('ID', 'ASC')
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * [ALG-02] Clear the derived-stale flag for a whole chunk at once. Called BEFORE the reindex
	 * (clear-then-work): a write landing during the reindex re-raises 'Y' and is picked up on the next
	 * agent tick.
	 *
	 * @param int[] $ids
	 */
	public function clearDerivedStale(array $ids): void
	{
		$this->setDerivedStale($ids, DocumentTable::DERIVED_STALE_NO);
	}

	/**
	 * [ALG-02] Put documents back into the dirty queue — after a failed rebuild, or when the agent runs
	 * out of time with part of its chunk untouched. Raising the flag is idempotent and cannot lose a
	 * concurrent write, which raises the very same value.
	 *
	 * @param int[] $ids
	 */
	public function markDerivedStale(array $ids): void
	{
		$this->setDerivedStale($ids, DocumentTable::DERIVED_STALE_YES);
	}

	/**
	 * One statement for the whole set, not one per document: every write to b_note_document drops the ORM
	 * cache of the entire table (Entity::cleanCache), so a chunk of 50 written one by one meant 50
	 * portal-wide invalidations in a row. updateMulti() collapses an identical payload into a single
	 * UPDATE ... IN (...) with a single cache drop.
	 *
	 * @param int[] $ids
	 */
	private function setDerivedStale(array $ids, string $value): void
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalizedIds))
		{
			return;
		}

		DocumentTable::updateMulti($normalizedIds, ['IS_DERIVED_STALE' => $value]);
	}

	/**
	 * Main-document lookups deliberately bypass MainDocumentFilter: they must be able
	 * to see the very row that every listing query hides.
	 */
	public function isMainDocument(int $id): bool
	{
		if ($id <= 0)
		{
			return false;
		}

		$row = DocumentTable::query()
			->setSelect(['ID'])
			->where('ID', $id)
			->where('IS_MAIN', DocumentTable::IS_MAIN_YES)
			->setLimit(1)
			->fetch()
		;

		return $row !== false;
	}

	/**
	 * Drops main documents from a caller-supplied id set in a single query. Bulk operations
	 * receive their roots straight from the client, so ids that no listing would ever expose
	 * still have to be rejected here.
	 *
	 * @param int[] $ids
	 * @return int[] the input ids, order preserved, minus every main document
	 */
	public function filterOutMainDocumentIds(array $ids): array
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalizedIds))
		{
			return [];
		}

		$rows = DocumentTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $normalizedIds)
			->where('IS_MAIN', DocumentTable::IS_MAIN_YES)
			->fetchAll()
		;

		if (empty($rows))
		{
			return $normalizedIds;
		}

		$mainIds = array_flip(array_map(static fn(array $row): int => (int)$row['ID'], $rows));

		return array_values(array_filter(
			$normalizedIds,
			static fn(int $id): bool => !isset($mainIds[$id]),
		));
	}

	public function findMainDocumentIdByCollectionId(int $collectionId): ?int
	{
		if ($collectionId <= 0)
		{
			return null;
		}

		$row = DocumentTable::query()
			->setSelect(['ID'])
			->where('COLLECTION_ID', $collectionId)
			->where('IS_MAIN', DocumentTable::IS_MAIN_YES)
			->setLimit(1)
			->fetch()
		;

		return $row === false ? null : (int)$row['ID'];
	}

	/**
	 * Batch main-document meta for a set of collections in a single query (no N+1).
	 * HAS_DESCRIPTION is computed in SQL as "MARKDOWN is non-empty": the compacted
	 * MARKDOWN is the authoritative record of the description's content, so an empty
	 * collaborative document (a persisted but empty Y.Doc) is correctly reported as
	 * having no description. The column is tested in SQL so the (potentially large)
	 * MARKDOWN blob is not transferred just to probe emptiness.
	 *
	 * @param int[] $collectionIds
	 * @return array<int, array{id: int, hasDescription: bool}> keyed by collectionId
	 */
	public function getMainDocumentSummaryByCollectionIds(array $collectionIds): array
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $collectionIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalizedIds))
		{
			return [];
		}

		$query = DocumentTable::query()
			->setSelect(['ID', 'COLLECTION_ID'])
			->registerRuntimeField(
				new \Bitrix\Main\ORM\Fields\ExpressionField(
					'HAS_DESCRIPTION',
					"CASE WHEN %s IS NOT NULL AND %s <> '' THEN 1 ELSE 0 END",
					['MARKDOWN', 'MARKDOWN'],
				),
			)
			->addSelect('HAS_DESCRIPTION')
			->whereIn('COLLECTION_ID', $normalizedIds)
			->where('IS_MAIN', DocumentTable::IS_MAIN_YES)
		;

		$result = [];
		foreach ($query->fetchAll() as $row)
		{
			$result[(int)$row['COLLECTION_ID']] = [
				'id' => (int)$row['ID'],
				'hasDescription' => (int)($row['HAS_DESCRIPTION'] ?? 0) === 1,
			];
		}

		return $result;
	}

	/**
	 * Batch raw MARKDOWN of the main document for a set of collections (single query).
	 *
	 * @param int[] $collectionIds
	 * @return array<int, string> raw MARKDOWN keyed by collectionId
	 */
	public function getMainDocumentMarkdownByCollectionIds(array $collectionIds): array
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $collectionIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalizedIds))
		{
			return [];
		}

		$rows = DocumentTable::query()
			->setSelect(['COLLECTION_ID', 'MARKDOWN'])
			->whereIn('COLLECTION_ID', $normalizedIds)
			->where('IS_MAIN', DocumentTable::IS_MAIN_YES)
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['COLLECTION_ID']] = (string)($row['MARKDOWN'] ?? '');
		}

		return $result;
	}

	/**
	 * Collections that still lack a main document, ordered by ID ascending and restricted
	 * to ID > $afterId. Used by the one-time backfill agent as a forward-only keyset scan:
	 * passing the highest id seen so far as $afterId skips already-examined collections
	 * (backfilled or permanently failing) instead of rescanning them on every chunk.
	 * $afterId = 0 (default) scans from the beginning.
	 *
	 * @return int[]
	 */
	public function findCollectionIdsWithoutMainDocument(int $limit, int $afterId = 0): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		// LEFT JOIN … IS NULL rather than NOT EXISTS: the IS_MAIN predicate sits in the join's ON,
		// so the join stays an index lookup over IX_NOTE_DOC_MAIN (COLLECTION_ID, IS_MAIN) and the
		// missing-row test is a plain NULL check on the joined key.
		$rows = CollectionTable::query()
			->setSelect(['ID'])
			->registerRuntimeField(
				new Reference(
					'MAIN_DOCUMENT',
					DocumentTable::class,
					Join::on('this.ID', 'ref.COLLECTION_ID')
						->where('ref.IS_MAIN', DocumentTable::IS_MAIN_YES),
					['join_type' => 'LEFT'],
				),
			)
			->whereNull('MAIN_DOCUMENT.ID')
			->where('ID', '>', max(0, $afterId))
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * [P4.T2] One keyset chunk of the document table for the link-index backfill: the id and the
	 * content format, the only two values the pass needs to tell a document it must reindex from a
	 * legacy json one it must count and leave alone.
	 *
	 * Deliberately unfiltered. The live index writer applies no filter either — archive, recycle bin
	 * and the collection main document are cut on read, not on write — so a filtered backfill would
	 * leave an old portal with an index different from the one a full re-save would produce, and a
	 * document restored from the bin would come back without its outgoing links.
	 *
	 * @return array<int, array{ID: int, CONTENT_FORMAT: string}>
	 */
	public function listIdsWithContentFormatAfter(int $afterId, int $limit): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$rows = DocumentTable::query()
			->setSelect(['ID', 'CONTENT_FORMAT'])
			->where('ID', '>', max(0, $afterId))
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(
			static fn(array $row): array => [
				'ID' => (int)$row['ID'],
				'CONTENT_FORMAT' => (string)($row['CONTENT_FORMAT'] ?? ''),
			],
			$rows,
		);
	}

	/**
	 * [P7.T1 / ALG-03] Batch documentId -> collectionId, the aggregation anchor
	 * for the notification drainer (a bucket's "which collection is this a mass
	 * operation on" key). One query for the whole set of unique ENTITY_ID in a
	 * dedup'd batch — avoids N+1 across the plan.
	 *
	 * @param int[] $documentIds
	 * @return array<int, int> documentId => collectionId
	 */
	public function getCollectionIds(array $documentIds): array
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $documentIds),
			static fn(int $id): bool => $id > 0,
		)));

		if (empty($normalizedIds))
		{
			return [];
		}

		$rows = DocumentTable::query()
			->setSelect(['ID', 'COLLECTION_ID'])
			->whereIn('ID', $normalizedIds)
			->fetchAll()
		;

		$map = [];
		foreach ($rows as $row)
		{
			$map[(int)$row['ID']] = (int)($row['COLLECTION_ID'] ?? 0);
		}

		return $map;
	}

	public function getMaxPosition(int $collectionId, ?int $parentId): int
	{
		$query = DocumentTable::query()
			->setSelect(['MAX_POSITION'])
			->registerRuntimeField(
				new \Bitrix\Main\ORM\Fields\ExpressionField('MAX_POSITION', 'MAX(%s)', 'POSITION'),
			)
			->where('COLLECTION_ID', $collectionId)
		;
		if ($parentId === null)
		{
			$query->whereNull('PARENT_ID');
		}
		else
		{
			$query->where('PARENT_ID', $parentId);
		}
		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);

		$row = $query->fetch();

		return (int)($row['MAX_POSITION'] ?? 0);
	}

	/**
	 * @return Document[]
	 */

	public function getCollectionDocuments(int $collectionId): array
	{
		$query = DocumentTable::query()
			->setSelect(self::TREE_SELECT)
			->where('COLLECTION_ID', $collectionId)
			->where('IS_ARCHIVED', 'N')
			->addOrder('PARENT_ID', 'ASC')
			->addOrder('POSITION', 'DESC')
			->addOrder('ID', 'DESC')
		;
		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);

		return $query->fetchCollection()->getAll();
	}

	/**
	 * @return Document[]
	 */
	public function getDocumentsByParent(
		int $collectionId,
		?int $parentId,
		?int $limit = null,
		int $offset = 0,
	): array
	{
		$query = $this->buildParentQuery($collectionId, $parentId, true)
			->setSelect(self::TREE_SELECT)
		;
		if ($limit !== null && $limit > 0)
		{
			$query->setLimit($limit);
			$query->setOffset(max(0, $offset));
		}
		$query->setCacheTtl(self::ORM_CACHE_TTL);

		return $query->fetchCollection()->getAll();
	}

	public function listByCollectionFlat(
		int $collectionId,
		?int $createdByUserId,
		int $limit,
		?int $afterPosition = null,
		?int $afterId = null,
		bool $rootsOnly = false,
	): array
	{
		// ORDER BY (POSITION DESC, ID DESC) matches user-controlled drag-n-drop order in the desktop tree
		// and uses IX_NOTE_DOC_BRANCH as a backward index scan without filesort.
		$query = DocumentTable::query()
			->setSelect(self::LIST_SELECT)
			->where('COLLECTION_ID', $collectionId)
			->where('IS_ARCHIVED', 'N')
			->addOrder('POSITION', 'DESC')
			->addOrder('ID', 'DESC')
		;

		if ($rootsOnly)
		{
			$query->whereNull('PARENT_ID');
		}

		if ($createdByUserId !== null && $createdByUserId > 0)
		{
			$query->where('CREATED_BY', $createdByUserId);
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

		if ($limit > 0)
		{
			$query->setLimit($limit);
		}

		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);

		return $query->fetchCollection()->getAll();
	}

	public function getDocumentMetaByParent(
		int $collectionId,
		?int $parentId,
		?int $limit = null,
		?int $afterPosition = null,
		?int $afterId = null,
	): array
	{
		$query = $this->buildParentQuery($collectionId, $parentId, true)
			->setSelect(self::LIST_SELECT)
		;
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
		if ($limit !== null && $limit > 0)
		{
			$query->setLimit($limit);
		}
		$query->setCacheTtl(self::ORM_CACHE_TTL);

		return $query->fetchCollection()->getAll();
	}

	public function getBranchDocuments(int $collectionId, ?int $parentId): array
	{
		return $this->buildParentQuery($collectionId, $parentId, false)
			->setSelect(self::TREE_SELECT)
			->fetchCollection()
			->getAll()
		;
	}

	public function getBranchDocumentsMeta(int $collectionId, ?int $parentId): array
	{
		return $this->buildParentQuery($collectionId, $parentId, false)
			->setSelect(self::LIST_SELECT)
			->fetchCollection()
			->getAll()
		;
	}

	public function getDocumentPathToRoot(int $documentId, int $maxDepth = 200): array
	{
		$path = [];
		$visited = [];
		$current = $this->getMetaById($documentId, self::TREE_SELECT);
		if ($current === null)
		{
			return [];
		}

		$targetCollectionId = $current->getCollectionId();
		$depth = 0;

		while ($current !== null && $depth < $maxDepth)
		{
			$parentId = $current->getParentId();
			if ($parentId === null || $parentId <= 0)
			{
				break;
			}

			if (isset($visited[$parentId]))
			{
				break;
			}

			$visited[$parentId] = true;
			$parent = $this->getMetaById($parentId, self::TREE_SELECT);
			if ($parent === null)
			{
				break;
			}

			if ($parent->getIsArchived() || $parent->getCollectionId() !== $targetCollectionId)
			{
				break;
			}

			$path[] = $parent;
			$current = $parent;
			$depth++;
		}

		return array_reverse($path);
	}

	/**
	 * [P6.T2 / ALG-02] Full walk up PARENT_ID to the root, with NO archived or
	 * collection-boundary cutoffs — unlike getDocumentPathToRoot() (used for
	 * breadcrumbs), a subtree subscriber on an ancestor above an archived branch,
	 * or above a collection boundary, must still be resolvable. Cycle-safe via a
	 * visited-set; $maxDepth is a hard backstop against pathological data.
	 *
	 * @return int[] ancestor ids ordered [parent, grandparent, ..., root]; does
	 *               NOT include $documentId itself
	 */
	public function getAncestorIds(int $documentId, int $maxDepth = 200): array
	{
		$ancestorIds = [];
		$visited = [$documentId => true];

		$current = $this->getMetaById($documentId, self::ANCESTOR_SELECT);
		if ($current === null)
		{
			return [];
		}

		$depth = 0;
		while ($depth < $maxDepth)
		{
			$parentId = $current->getParentId();
			if ($parentId === null || $parentId <= 0 || isset($visited[$parentId]))
			{
				break;
			}

			$parent = $this->getMetaById($parentId, self::ANCESTOR_SELECT);
			if ($parent === null)
			{
				break;
			}

			$visited[$parentId] = true;
			$ancestorIds[] = $parentId;
			$current = $parent;
			$depth++;
		}

		return $ancestorIds;
	}

	/**
	 * [P2.T1] Batch form of getAncestorIds(): walks PARENT_ID level by level for a whole
	 * set of documents, one query per level, so the query count follows the deepest chain
	 * instead of the size of the set. Same semantics as the single walk - no archived and
	 * no collection-boundary cutoff, same $maxDepth backstop - and a per-source visited set,
	 * because a shared one would cut a common chain short for every document after the first.
	 *
	 * The single-document walk is kept as its own method on purpose: it reads one cached row
	 * per level (hot path of the notification drainer and the bell), while this one trades the
	 * cache for a constant number of queries per level.
	 *
	 * @param int[] $documentIds
	 * @return array<int, int[]> documentId => ancestor ids ordered [parent, ..., root];
	 *                           a document that does not exist maps to an empty array
	 */
	public function getAncestorIdsMap(array $documentIds, int $maxDepth = 200): array
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalizedIds))
		{
			return [];
		}

		$ancestorIds = array_fill_keys($normalizedIds, []);
		$visited = [];
		$current = [];

		$rows = $this->getByIds($normalizedIds, self::ANCESTOR_SELECT);
		foreach ($normalizedIds as $documentId)
		{
			if (!isset($rows[$documentId]))
			{
				continue;
			}

			$visited[$documentId] = [$documentId => true];
			$current[$documentId] = (int)($rows[$documentId]['PARENT_ID'] ?? 0);
		}

		$depth = 0;
		while ($depth < $maxDepth && !empty($current))
		{
			$wantedIds = [];
			$parentByDocument = [];
			foreach ($current as $documentId => $parentId)
			{
				if ($parentId <= 0 || isset($visited[$documentId][$parentId]))
				{
					continue;
				}

				$parentByDocument[$documentId] = $parentId;
				$wantedIds[$parentId] = true;
			}

			if (empty($wantedIds))
			{
				break;
			}

			$parentRows = $this->getByIds(array_keys($wantedIds), self::ANCESTOR_SELECT);
			$current = [];
			foreach ($parentByDocument as $documentId => $parentId)
			{
				if (!isset($parentRows[$parentId]))
				{
					continue;
				}

				$visited[$documentId][$parentId] = true;
				$ancestorIds[$documentId][] = $parentId;
				$current[$documentId] = (int)($parentRows[$parentId]['PARENT_ID'] ?? 0);
			}

			$depth++;
		}

		return $ancestorIds;
	}

	public function hasChildren(int $collectionId, int $parentId): bool
	{
		if ($collectionId <= 0 || $parentId <= 0)
		{
			return false;
		}

		$query = DocumentTable::query()
			->setSelect(['ID'])
			->where('COLLECTION_ID', $collectionId)
			->where('PARENT_ID', $parentId)
			->where('IS_ARCHIVED', 'N')
			->setLimit(1)
			->setCacheTtl(self::ORM_CACHE_TTL)
		;
		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);

		return $query->exec()->fetch() !== false;
	}

	public function getHasChildrenMap(int $collectionId, array $documentIds): array
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $documentIds),
			static fn(int $id): bool => $id > 0,
		)));

		if (empty($normalizedIds))
		{
			return [];
		}

		$childrenCountMap = $this->getChildrenCountMapByParentIds($collectionId, $normalizedIds);
		$result = [];
		foreach ($normalizedIds as $id)
		{
			$result[$id] = (int)($childrenCountMap[$id] ?? 0) > 0;
		}

		return $result;
	}

	public function getChildrenCountMapByParentIds(int $collectionId, array $parentIds): array
	{
		$normalizedParentIds = array_values(array_unique(array_map(
			static fn($id): int => (int)$id,
			$parentIds
		)));

		if (empty($normalizedParentIds))
		{
			return [];
		}

		$query = DocumentTable::query()
			->setSelect(['PARENT_ID'])
			->where('COLLECTION_ID', $collectionId)
			->where('IS_ARCHIVED', 'N')
			->whereIn('PARENT_ID', $normalizedParentIds)
			->setDistinct()
			->setCacheTtl(self::ORM_CACHE_TTL)
		;
		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);
		$query = $query->exec();

		$result = [];
		while ($row = $query->fetch())
		{
			$parentId = (int)($row['PARENT_ID'] ?? 0);
			if ($parentId > 0)
			{
				$result[$parentId] = 1;
			}
		}

		return $result;
	}

	/**
	 * Batch form of the probe above: several knowledge bases in one query. Returns a flat set of
	 * (COLLECTION_ID, PARENT_ID) pairs that own at least one live child, grouping is left to the
	 * caller. `IN` on COLLECTION_ID keeps the leftmost index prefix in use.
	 *
	 * @param int[] $collectionIds
	 * @param int[] $parentIds
	 * @return array<int, array{collectionId: int, parentId: int}>
	 */
	public function getChildParentPairsByCollections(array $collectionIds, array $parentIds): array
	{
		$normalizedCollectionIds = $this->normalizePositiveIds($collectionIds);
		$normalizedParentIds = $this->normalizePositiveIds($parentIds);

		if (empty($normalizedCollectionIds) || empty($normalizedParentIds))
		{
			return [];
		}

		$query = DocumentTable::query()
			->setSelect(['COLLECTION_ID', 'PARENT_ID'])
			->whereIn('COLLECTION_ID', $normalizedCollectionIds)
			->where('IS_ARCHIVED', 'N')
			->whereIn('PARENT_ID', $normalizedParentIds)
			->setDistinct()
			->setCacheTtl(self::ORM_CACHE_TTL)
		;
		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);
		$result = $query->exec();

		$pairs = [];
		while ($row = $result->fetch())
		{
			$collectionId = (int)($row['COLLECTION_ID'] ?? 0);
			$parentId = (int)($row['PARENT_ID'] ?? 0);
			if ($collectionId > 0 && $parentId > 0)
			{
				$pairs[] = ['collectionId' => $collectionId, 'parentId' => $parentId];
			}
		}

		return $pairs;
	}

	/**
	 * @param array<int, mixed> $ids
	 * @return int[]
	 */
	private function normalizePositiveIds(array $ids): array
	{
		return array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
	}

	public function getChildrenCountMap(int $collectionId): array
	{
		$query = DocumentTable::query()
			->setSelect(['PARENT_ID'])
			->where('COLLECTION_ID', $collectionId)
			->where('IS_ARCHIVED', 'N')
			->whereNotNull('PARENT_ID')
			->setDistinct()
		;
		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);
		$query = $query->exec();

		$result = [];
		while ($row = $query->fetch())
		{
			$parentId = (int)($row['PARENT_ID'] ?? 0);
			if ($parentId > 0)
			{
				$result[$parentId] = 1;
			}
		}

		return $result;
	}

	/**
	 * @param bool $excludeArchived when true, archived nodes are not traversed: they are omitted
	 *   from the result and their own subtree is not walked (an archived subtree is already
	 *   archived as a whole). Used by the bulk dry-run resolver so the affected count does not
	 *   include descendants that are already archived. Default false preserves existing callers.
	 * @param int|null $limit stop the walk once this many ids (root included) have been collected.
	 *   For callers that only need to know whether the subtree is bigger than some bound — the walk
	 *   is level-by-level, so without a limit a huge branch is enumerated in full before the caller
	 *   can decide anything. Null (default) walks everything.
	 *   A truncated result is NOT an authoritative subtree: which ids survive depends on the database's
	 *   row order within a level (there is no ORDER BY), so it may differ between runs. Use it to
	 *   compare the size against the bound, never as the list to act on.
	 */
	public function getSubtreeIds(
		int $rootId,
		int $collectionId,
		bool $includeRecycleBin = false,
		bool $excludeArchived = false,
		?int $limit = null,
	): array
	{
		if ($rootId <= 0 || $collectionId <= 0 || ($limit !== null && $limit <= 0))
		{
			return [];
		}

		$idsMap = [$rootId => true];
		$parentIds = [$rootId];
		while (!empty($parentIds))
		{
			if ($limit !== null && count($idsMap) >= $limit)
			{
				break;
			}

			$query = DocumentTable::query()
				->setSelect(['ID'])
				->where('COLLECTION_ID', $collectionId)
				->whereIn('PARENT_ID', $parentIds)
			;
			if ($excludeArchived)
			{
				$query->where('IS_ARCHIVED', 'N');
			}
			if (!$includeRecycleBin)
			{
				$this->recycleBinFilter()->applyExclusion($query);
				$this->mainDocumentFilter()->applyExclusion($query);
			}
			if ($limit !== null)
			{
				// A single level can be arbitrarily wide, so the bound belongs on the query too.
				$query->setLimit($limit - count($idsMap));
			}
			// Streamed, not fetchCollection(): a wide level would otherwise hydrate an entity object
			// per row just to read the id off it.
			$rows = $query->exec();

			$nextParentIds = [];
			while ($row = $rows->fetch())
			{
				$childId = (int)$row['ID'];
				if ($childId <= 0 || isset($idsMap[$childId]))
				{
					continue;
				}

				$idsMap[$childId] = true;
				$nextParentIds[] = $childId;
			}

			$parentIds = $nextParentIds;
		}

		return array_map('intval', array_keys($idsMap));
	}

	/**
	 * Batch variant of {@see getSubtreeIds()}: walks several subtrees at once, one query per depth
	 * level for the whole batch instead of a full walk per root. Written for the reparent path, where
	 * every direct child of a removed node needs its own subtree and the per-root walks otherwise add
	 * up to a query storm on a wide branch.
	 *
	 * The roots are expected to be disjoint (siblings). A node reachable from more than one root is
	 * attributed to the one that reaches it first, so the returned sets never overlap.
	 *
	 * @param int[] $rootIds
	 * @return array<int, int[]> rootId => subtree ids, root included, in BFS order
	 */
	public function getSubtreeIdsByRoot(array $rootIds, int $collectionId, bool $includeRecycleBin = false): array
	{
		$roots = [];
		foreach ($rootIds as $rootId)
		{
			$rootId = (int)$rootId;
			if ($rootId > 0)
			{
				$roots[$rootId] = true;
			}
		}
		if (empty($roots) || $collectionId <= 0)
		{
			return [];
		}

		$result = [];
		$ownerByNode = [];
		foreach (array_keys($roots) as $rootId)
		{
			$result[$rootId] = [$rootId];
			$ownerByNode[$rootId] = $rootId;
		}

		$parentIds = array_keys($roots);
		while (!empty($parentIds))
		{
			$query = DocumentTable::query()
				->setSelect(['ID', 'PARENT_ID'])
				->where('COLLECTION_ID', $collectionId)
				->whereIn('PARENT_ID', $parentIds)
			;
			if (!$includeRecycleBin)
			{
				$this->recycleBinFilter()->applyExclusion($query);
			}
			$rows = $query->exec();

			$nextParentIds = [];
			while ($row = $rows->fetch())
			{
				$childId = (int)$row['ID'];
				$owner = $ownerByNode[(int)$row['PARENT_ID']] ?? 0;
				if ($childId <= 0 || $owner === 0 || isset($ownerByNode[$childId]))
				{
					continue;
				}

				$ownerByNode[$childId] = $owner;
				$result[$owner][] = $childId;
				$nextParentIds[] = $childId;
			}

			$parentIds = $nextParentIds;
		}

		return $result;
	}

	/**
	 * Direct live children of $parentId within $collectionId (one level only; archived and
	 * recycle-bin rows are excluded). Read primitive for ReparentService — the service owns
	 * the re-hang orchestration, the repository only reads.
	 *
	 * @return Document[]
	 */
	public function listDirectChildren(int $parentId, int $collectionId): array
	{
		if ($parentId <= 0 || $collectionId <= 0)
		{
			return [];
		}

		$query = DocumentTable::query()
			->setSelect(self::TREE_SELECT)
			->where('COLLECTION_ID', $collectionId)
			->where('PARENT_ID', $parentId)
			->where('IS_ARCHIVED', 'N')
		;
		$this->recycleBinFilter()->applyExclusion($query);

		return $query->fetchCollection()->getAll();
	}

	/**
	 * Batch partial update applying the same field set to every id. Thin write primitive
	 * over DocumentTable::updateMulti; the orchestration (which ids, which fields, follow-up
	 * reorder) stays in the calling domain service.
	 *
	 * @param int[] $ids
	 * @param array<string, mixed> $fields
	 */
	public function updateMulti(array $ids, array $fields): void
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalizedIds) || empty($fields))
		{
			return;
		}

		DocumentTable::updateMulti($normalizedIds, $fields, true);
	}

	/**
	 * Legacy: используется DeleteDocumentCommand (soft-delete без метаданных архивации).
	 * Когда появится корзина — Delete*Command переедет в свою таблицу, и метод будет удалён.
	 */
	public function archiveByIdsLegacy(array $ids): void
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalizedIds))
		{
			return;
		}

		DocumentTable::updateMulti($normalizedIds, ['IS_ARCHIVED' => 'Y'], true);
	}

	public function archiveByIds(array $ids, int $userId): void
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalizedIds))
		{
			return;
		}

		$connection = Application::getConnection();
		$placeholders = implode(',', $normalizedIds);
		$userIdSafe = (int)$userId;
		$nowSql = $this->currentDateTimeSql($connection);
		$connection->queryExecute(
			"UPDATE b_note_document"
			. " SET IS_ARCHIVED = 'Y',"
			. " ARCHIVED_AT = {$nowSql},"
			. " ARCHIVED_BY = {$userIdSafe},"
			. " UPDATED_AT = {$nowSql},"
			. " UPDATED_BY = {$userIdSafe}"
			. " WHERE ID IN ({$placeholders})"
			. " AND IS_ARCHIVED = 'N'"
		);
		DocumentTable::cleanCache();
	}

	public function restoreByIds(array $ids, int $userId): void
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalizedIds))
		{
			return;
		}

		$connection = Application::getConnection();
		$placeholders = implode(',', $normalizedIds);
		$userIdSafe = (int)$userId;
		$nowSql = $this->currentDateTimeSql($connection);
		$connection->queryExecute(
			"UPDATE b_note_document"
			. " SET IS_ARCHIVED = 'N',"
			. " ARCHIVED_AT = NULL,"
			. " ARCHIVED_BY = NULL,"
			. " UPDATED_AT = {$nowSql},"
			. " UPDATED_BY = {$userIdSafe}"
			. " WHERE ID IN ({$placeholders})"
			. " AND IS_ARCHIVED = 'Y'"
		);
		DocumentTable::cleanCache();
	}

	public function liftArchivedOrphansToRoot(array $ids): void
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalizedIds))
		{
			return;
		}

		$rows = DocumentTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $normalizedIds)
			->whereNotNull('PARENT_ID')
			->where(Query::filter()->logic('or')
				->whereNull('PARENT.ID')
				->where('PARENT.IS_ARCHIVED', 'Y')
			)
			->fetchAll()
		;

		$idsToLift = array_map(static fn(array $row): int => (int)$row['ID'], $rows);
		if (empty($idsToLift))
		{
			return;
		}

		DocumentTable::updateMulti($idsToLift, ['PARENT_ID' => null], true);
	}

	public function getLiveCollectionDocumentIds(int $collectionId): array
	{
		if ($collectionId <= 0)
		{
			return [];
		}

		$query = DocumentTable::query()
			->setSelect(['ID'])
			->where('COLLECTION_ID', $collectionId)
			->where('IS_ARCHIVED', 'N')
		;
		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);
		$rows = $query->fetchAll();

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * Top-level live document ids of a collection (PARENT_ID IS NULL). "Select all" over-section
	 * commands apply to every document but record history only on these roots, mirroring the
	 * single-command root-only event.
	 *
	 * @return int[]
	 */
	public function getLiveCollectionRootIds(int $collectionId): array
	{
		if ($collectionId <= 0)
		{
			return [];
		}

		$query = DocumentTable::query()
			->setSelect(['ID'])
			->where('COLLECTION_ID', $collectionId)
			->where('IS_ARCHIVED', 'N')
			->where('PARENT_ID', null)
		;
		$this->recycleBinFilter()->applyExclusion($query);
		// The main document is a PARENT_ID=NULL row too, but it is not part of the tree:
		// bulk "select all in collection" must never sweep the collection's description.
		$this->mainDocumentFilter()->applyExclusion($query);
		$rows = $query->fetchAll();

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	public function archiveByCollectionId(int $collectionId, int $userId = 0): void
	{
		$this->archiveByIds($this->getLiveCollectionDocumentIds($collectionId), max(0, $userId));
	}

	/**
	 * Keyset chunk iterator for cascade-delete in DeleteCollectionCommand.
	 * Selects only fields needed to enumerate descendants for the recycle-bin cascade.
	 * The main document is excluded: it is the collection's description carrier, has no place
	 * in the tree, and therefore nothing to restore into — DeleteCollectionCommand hard-deletes
	 * it together with the collection instead of trashing it.
	 *
	 * @return array<int, array{ID: int, IS_ARCHIVED: bool, PARENT_ID: ?int, POSITION: int, TITLE: string}>
	 */
	public function listByCollectionForCascadeDelete(int $collectionId, int $afterId, int $limit): array
	{
		if ($collectionId <= 0 || $limit <= 0)
		{
			return [];
		}

		$query = DocumentTable::query()
			->setSelect(['ID', 'IS_ARCHIVED', 'PARENT_ID', 'POSITION', 'TITLE'])
			->where('COLLECTION_ID', $collectionId)
			->where('ID', '>', max(0, $afterId))
			->addOrder('ID', 'ASC')
			->setLimit($limit)
		;
		$this->mainDocumentFilter()->applyExclusion($query);
		$rows = $query->fetchAll();

		return array_map(
			static fn(array $row): array => [
				'ID' => (int)$row['ID'],
				'IS_ARCHIVED' => ($row['IS_ARCHIVED'] ?? 'N') === 'Y',
				'PARENT_ID' => isset($row['PARENT_ID']) ? (int)$row['PARENT_ID'] : null,
				'POSITION' => (int)($row['POSITION'] ?? 0),
				'TITLE' => (string)($row['TITLE'] ?? ''),
			],
			$rows,
		);
	}

	public function deleteById(int $id): void
	{
		if ($id > 0)
		{
			DocumentTable::delete($id);
		}
	}

	/**
	 * @param int[] $documentIds
	 */
	public function deleteByIds(array $documentIds): void
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
		$connection->queryExecute("DELETE FROM b_note_document WHERE ID IN ({$placeholders})");
	}

	/**
	 * Second-precision "now" SQL like the ORM writes DatetimeField. Raw NOW() yields
	 * microseconds on PG, which breaks the second-precision ARCHIVED_AT cursor pagination.
	 */
	private function currentDateTimeSql(\Bitrix\Main\DB\Connection $connection): string
	{
		return $connection->getSqlHelper()->getCharToDateFunction((new DateTime())->format('Y-m-d H:i:s'));
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

	private function buildParentQuery(int $collectionId, ?int $parentId, bool $activeOnly): Query
	{
		$query = DocumentTable::query()
			->where('COLLECTION_ID', $collectionId)
			->addOrder('POSITION', 'DESC')
			->addOrder('ID', 'DESC')
		;

		if ($activeOnly)
		{
			$query->where('IS_ARCHIVED', 'N');
		}

		if ($parentId === null)
		{
			$query->whereNull('PARENT_ID');
		}
		else
		{
			$query->where('PARENT_ID', $parentId);
		}

		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);

		return $query;
	}

}

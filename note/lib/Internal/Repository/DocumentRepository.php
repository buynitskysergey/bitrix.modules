<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\Result as OrmResult;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Model\CollectionTable;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\DocumentViewTable;
use Bitrix\Note\Internal\Service\Document\MainDocumentFilter;
use Bitrix\Note\Internal\Service\RecycleBin\RecycleBinFilter;
use Bitrix\Note\Internal\Util\CursorDate;
use Bitrix\Note\Internal\Util\IdNormalizer;

class DocumentRepository
{
	private const ORM_CACHE_TTL = 15;

	// States a page may ask for. The values are those of the read contract; the domain does not
	// reference the contract itself, the same way {@see DocumentAccessService::STATE_ARCHIVED} does not.
	public const STATE_LIVE = 'live';
	public const STATE_ARCHIVED = DocumentAccessService::STATE_ARCHIVED;
	public const STATE_TRASHED = DocumentAccessService::STATE_TRASHED;

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

	/**
	 * Everything a move needs to know about a node and nothing else: which node it is and where it is
	 * now. Public, unlike its neighbours, because the readers are the bulk commands of the Public
	 * layer, and a whole set read by LIST_SELECT instead would carry the memory nobody capped.
	 */
	public const MOVE_META_SELECT = ['ID', 'COLLECTION_ID', 'PARENT_ID'];

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
	 * Fields the node of the public tree is built from: the title, the
	 * place in the tree and the date of the last change, and no author of it.
	 */
	private const PUBLIC_TREE_SELECT = [
		'ID', 'COLLECTION_ID', 'PARENT_ID', 'TITLE', 'POSITION', 'IS_ARCHIVED', 'UPDATED_AT',
	];

	/**
	 * Fields the public answer by a document is built from - the white list and nothing
	 * else: no author of the last change, no counters, no rights.
	 *
	 * Neither mediumText column is here. YJS_STATE was never read from the object at all -
	 * PublicResponseMapper re-reads the snapshot by itself, after the journal and never before it -
	 * and MARKDOWN is only ever the fallback of a document whose snapshot is empty, so the mapper
	 * reads it by getRawMarkdown() at the one branch that needs it. A document body is the largest
	 * thing this table stores, and the public circuit is anonymous: reading it to answer anything
	 * other than "here is the text" is a price not proportional to the answer.
	 */
	private const PUBLIC_DOCUMENT_SELECT = [
		'ID', 'COLLECTION_ID', 'PARENT_ID', 'TITLE', 'CONTENT_FORMAT', 'UPDATED_AT',
	];

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
	 * Shared forward-only projection write used by both materialization and compaction. Runs
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
	 * Current materialization cursor, read uncached. NULL means the document has never been
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
	 * Those of the given documents whose projection has never been written under the materialization
	 * contract: a collaborative document (yjs, or the legacy json tree) with no cursor at all. Its
	 * MARKDOWN was written by some client before the cursor existed, and nothing records which one -
	 * the editor of 26.0.0 wrote attachments into it as nothing at all - so the text is no statement
	 * about the files the document carries. A markdown document is not among them: its text is written
	 * by the server from what the caller sent and is complete by construction.
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	public function filterIdsWithoutMaterializedProjection(array $ids): array
	{
		$normalized = IdNormalizer::normalize($ids);
		if (empty($normalized))
		{
			return [];
		}

		$rows = DocumentTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $normalized)
			->whereNull('MATERIALIZED_UPTO_ID')
			->where('CONTENT_FORMAT', '!=', DocumentTable::CONTENT_FORMAT_MD)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * Ceiling for a client-supplied materialization cursor, shared by materialization and
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
	 * The cursor for a writer that replaced the whole text rather than continuing it - overwrite,
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
	 * Dirty queue for the freshness agent: ids of documents whose derived projections (search,
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
	 * Clear the derived-stale flag for a whole chunk at once. Called BEFORE the reindex
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
	 * Put documents back into the dirty queue - after a failed rebuild, or when the agent runs
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
	 * One keyset chunk of the document table for the link-index backfill: the id and the
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
	 * Documents each of the given users created within the period, counted for the whole
	 * batch in one grouped query - the annual summary walks users in batches, and a per-user query
	 * would multiply the index range scans by the size of the batch.
	 *
	 * Period bounds are half-open: from is included, to is not. Neither the archive nor the recycle
	 * bin subtracts from the count - "how many did I create this year" is a historical fact - and
	 * leaving both joins out is also what keeps the query on IX_NOTE_DOC_AUTHOR alone.
	 *
	 * @param int[] $userIds
	 * @return array<int, int> userId => count, users with no documents are absent
	 */
	public function countCreatedByAuthors(array $userIds, Date $from, Date $to): array
	{
		$normalizedIds = $this->normalizePositiveIds($userIds);
		if (empty($normalizedIds))
		{
			return [];
		}

		// GROUP BY CREATED_BY is added by the core: Query::hasAggregation() sees the aggregate in
		// the select list and groups the non-aggregate fields itself.
		$query = DocumentTable::query()
			->registerRuntimeField(new ExpressionField('CNT', 'COUNT(*)'))
			->setSelect(['CREATED_BY', 'CNT'])
			->whereIn('CREATED_BY', $normalizedIds)
			->where('CREATED_AT', '>=', $this->toDateTime($from))
			->where('CREATED_AT', '<', $this->toDateTime($to))
		;
		$this->mainDocumentFilter()->applyExclusion($query);

		return $this->fetchCountsByAuthor($query);
	}

	/**
	 * Reads other employees left within the period on documents these users authored.
	 * One read = one employee on one document: b_note_document_view keeps a single row per
	 * (document, reader) with the time of the *last* view, so re-opening a document does not add
	 * a row and the number of openings does not exist in the data at all.
	 *
	 * Consequence of keeping only the last view, accepted by the product: this counts employees whose
	 * *last* view falls inside the period, not everyone who read within it. A reader who opened a
	 * document in November and came back in December drops out - their single row moved past the upper
	 * bound. There is no view history to correct this against.
	 *
	 * Deliberately NOT one join. A single query would leave the join order to the planner, and the
	 * only index on the views table starts with DOCUMENT_ID, so "views within the period" alone
	 * cannot be served by it: entering from the views side means reading almost the whole table on
	 * every batch. So the documents of the batch are walked by keyset over IX_NOTE_DOC_AUTHOR, and
	 * their views are counted per chunk of ids - a range scan per document, whatever the planner
	 * thinks. Chunking also keeps the id list of a prolific author out of one giant IN.
	 *
	 * @param int[] $userIds
	 * @return array<int, int> userId => count, authors with no reads are absent
	 */
	public function countReadsByAuthors(array $userIds, Date $from, Date $to): array
	{
		$normalizedIds = $this->normalizePositiveIds($userIds);
		if (empty($normalizedIds))
		{
			return [];
		}

		$fromAt = $this->toDateTime($from);
		$toAt = $this->toDateTime($to);
		$counts = [];
		$afterId = 0;

		while (true)
		{
			$authorByDocument = $this->fetchAuthorsByDocumentChunk($normalizedIds, $afterId);
			if (empty($authorByDocument))
			{
				break;
			}

			$documentIds = array_keys($authorByDocument);
			$afterId = max($documentIds);

			foreach ($this->countViewsByDocument($documentIds, $fromAt, $toAt) as $documentId => $views)
			{
				$author = $authorByDocument[$documentId] ?? null;
				if ($author !== null)
				{
					$counts[$author] = ($counts[$author] ?? 0) + $views;
				}
			}

			// The author reading their own document is not a read. The views table knows nothing
			// about authorship, so own views are subtracted afterwards: the primary key allows one
			// row per (document, reader), hence at most one subtraction per document.
			foreach ($this->listOwnViews($documentIds, $normalizedIds, $fromAt, $toAt) as [$documentId, $viewer])
			{
				if (($authorByDocument[$documentId] ?? null) === $viewer)
				{
					// The key exists by then: an own view inside the period means the document was
					// viewed inside the period, so the counting above created it. Written without
					// relying on that anyway - the invariant holds only while both queries carry the
					// same period, and each of them spells that period out separately.
					$counts[$viewer] = max(0, ($counts[$viewer] ?? 0) - 1);
				}
			}

			if (count($documentIds) < $this->readsChunkSize())
			{
				break;
			}
		}

		return array_filter($counts, static fn(int $total): bool => $total > 0);
	}

	/**
	 * One keyset chunk of the documents these users authored, as documentId => authorId. Period is
	 * not applied here: a document written before the period still collects reads inside it.
	 *
	 * Main documents are cut out: opening the "About the knowledge base" tab physically writes a
	 * view of that hidden document, and counting it as a read of "my document" would surprise the
	 * author - they do not consider it a document of theirs.
	 *
	 * @param int[] $userIds
	 * @return array<int, int> documentId => authorId
	 */
	private function fetchAuthorsByDocumentChunk(array $userIds, int $afterId): array
	{
		$query = DocumentTable::query()
			->setSelect(['ID', 'CREATED_BY'])
			->whereIn('CREATED_BY', $userIds)
			->where('ID', '>', $afterId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($this->readsChunkSize())
		;
		$this->mainDocumentFilter()->applyExclusion($query);

		$authorByDocument = [];
		foreach ($query->fetchAll() as $row)
		{
			$authorByDocument[(int)$row['ID']] = (int)$row['CREATED_BY'];
		}

		return $authorByDocument;
	}

	/**
	 * @param int[] $documentIds
	 * @return array<int, int> documentId => number of views within the period
	 */
	private function countViewsByDocument(array $documentIds, DateTime $from, DateTime $to): array
	{
		$rows = DocumentViewTable::query()
			->registerRuntimeField(new ExpressionField('CNT', 'COUNT(*)'))
			->setSelect(['DOCUMENT_ID', 'CNT'])
			->whereIn('DOCUMENT_ID', $documentIds)
			->where('VIEWED_AT', '>=', $from)
			->where('VIEWED_AT', '<', $to)
			->fetchAll()
		;

		$views = [];
		foreach ($rows as $row)
		{
			$views[(int)$row['DOCUMENT_ID']] = (int)$row['CNT'];
		}

		return $views;
	}

	/**
	 * Views these same users left on the documents of the chunk. Cross reads (one author of the
	 * batch reading another one's document) come back too and are filtered by the caller, which is
	 * the only place that knows who authored what.
	 *
	 * @param int[] $documentIds
	 * @param int[] $userIds
	 * @return array<int, array{0: int, 1: int}>
	 */
	private function listOwnViews(array $documentIds, array $userIds, DateTime $from, DateTime $to): array
	{
		$rows = DocumentViewTable::query()
			->setSelect(['DOCUMENT_ID', 'USER_ID'])
			->whereIn('DOCUMENT_ID', $documentIds)
			->whereIn('USER_ID', $userIds)
			->where('VIEWED_AT', '>=', $from)
			->where('VIEWED_AT', '<', $to)
			->fetchAll()
		;

		return array_map(
			static fn(array $row): array => [(int)$row['DOCUMENT_ID'], (int)$row['USER_ID']],
			$rows,
		);
	}

	/**
	 * Documents per chunk of the annual summary reads walk. Sized to keep the IN list of one chunk
	 * comfortable for the MySQL 5.6 planner while a batch of ordinary authors still fits in a single
	 * pass. A method rather than a constant so a test can shrink it and actually walk more than one
	 * chunk.
	 */
	protected function readsChunkSize(): int
	{
		return 1000;
	}

	/**
	 * @return array<int, int> CREATED_BY => CNT
	 */
	private function fetchCountsByAuthor(Query $query): array
	{
		$counts = [];
		foreach ($query->fetchAll() as $row)
		{
			$counts[(int)$row['CREATED_BY']] = (int)$row['CNT'];
		}

		return $counts;
	}

	/**
	 * The annual summary hands over plain Date bounds; converting them explicitly keeps the
	 * comparison a datetime one on every driver instead of relying on implicit casting.
	 */
	private function toDateTime(Date $value): DateTime
	{
		return $value instanceof DateTime ? $value : DateTime::createFromTimestamp($value->getTimestamp());
	}

	/**
	 * Batch documentId -> collectionId, the aggregation anchor
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
	 * One live document inside one knowledge base, read for the public circuit.
	 *
	 * The scope is a condition of the query, not a comparison after the read: there is nowhere to
	 * forget it. Liveness is the same predicate the tree read uses - not archived, not in the
	 * recycle bin, not the hidden main document - and archivedness is spelled out explicitly
	 * because it has no predicate object of its own.
	 *
	 * getById() and getMetaById() are deliberately not reused: they filter none of the three and
	 * read through the ORM cache, while a revoked access has to take effect at once.
	 */
	public function getLiveInCollection(int $collectionId, int $documentId): ?Document
	{
		if ($collectionId <= 0 || $documentId <= 0)
		{
			return null;
		}

		return $this->liveInCollectionQuery($collectionId)
			->setSelect(self::PUBLIC_DOCUMENT_SELECT)
			->where('ID', $documentId)
			->fetchObject()
		;
	}

	/**
	 * Whether the scope of a link holds this document, and nothing beyond that.
	 *
	 * The same question getLiveInCollection() answers, asked where the answer is used as yes or no:
	 * the parent of an opened document, the target of a mention or of a backlink, the document
	 * behind a served picture. All of them used to read the document whole - its body included -
	 * to produce a boolean, and the picture path paid it once per image on the page.
	 *
	 * The predicate is not repeated here: it is the one builder both methods share, so the two
	 * answers cannot drift apart.
	 */
	public function existsLiveInCollection(int $collectionId, int $documentId): bool
	{
		if ($collectionId <= 0 || $documentId <= 0)
		{
			return false;
		}

		return $this->liveInCollectionQuery($collectionId)
			->setSelect(['ID'])
			->where('ID', $documentId)
			->fetch() !== false
		;
	}

	/**
	 * The whole liveness predicate of the public circuit in one place: inside the knowledge base,
	 * not archived, not in the recycle bin, not the hidden main document.
	 *
	 * Every public read is built from here rather than repeating the three conditions, because a
	 * predicate spelled out in several places is a predicate that will one day be relaxed in one of
	 * them: the narrow reads answer the same question as the wide ones and must answer it the same.
	 */
	private function liveInCollectionQuery(int $collectionId): Query
	{
		$query = DocumentTable::query()
			->where('COLLECTION_ID', $collectionId)
			->where('IS_ARCHIVED', 'N')
		;
		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);

		return $query;
	}

	/**
	 * Titles of the live documents of one knowledge base among the given ids, read for the public
	 * circuit: id => title, and an id that is missing from the answer is an id the visitor may not
	 * be told anything about.
	 *
	 * The liveness predicate is the one of getLiveInCollection(): a mention of an archived, a
	 * recycled or the hidden main document is as unavailable as a mention of someone else's.
	 *
	 * @param int[] $documentIds
	 * @return array<int, string>
	 */
	public function getLiveTitlesInCollection(int $collectionId, array $documentIds): array
	{
		$ids = array_values(array_unique(array_filter(
			array_map('intval', $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
		if ($collectionId <= 0 || $ids === [])
		{
			return [];
		}

		$query = $this->liveInCollectionQuery($collectionId)
			->setSelect(['ID', 'TITLE'])
			->whereIn('ID', $ids)
		;

		$titles = [];
		foreach ($query->fetchAll() as $row)
		{
			$titles[(int)$row['ID']] = (string)($row['TITLE'] ?? '');
		}

		return $titles;
	}

	/**
	 * The hidden main document of one knowledge base - the carrier of its description - read for the
	 * public circuit.
	 *
	 * The mirror image of getLiveInCollection(): the same scope, the same freshness and the same
	 * liveness, with the one predicate inverted. It is a separate method and not a flag because the
	 * hidden main document is the one thing the scoped reader must never return, and the two callers
	 * are asking different questions.
	 */
	public function getLiveMainInCollection(int $collectionId): ?Document
	{
		if ($collectionId <= 0)
		{
			return null;
		}

		return $this->liveMainInCollectionQuery($collectionId)
			->setSelect(self::PUBLIC_DOCUMENT_SELECT)
			->fetchObject()
		;
	}

	/**
	 * The id of the same hidden main document, for the two callers that only need to recognise it:
	 * the file path, which lets the pictures of the description out, and the batch that describes
	 * those pictures. Neither shows the text, and the text is the heaviest thing in the row.
	 */
	public function getLiveMainIdInCollection(int $collectionId): ?int
	{
		if ($collectionId <= 0)
		{
			return null;
		}

		$row = $this->liveMainInCollectionQuery($collectionId)
			->setSelect(['ID'])
			->fetch()
		;

		return $row === false ? null : (int)$row['ID'];
	}

	private function liveMainInCollectionQuery(int $collectionId): Query
	{
		$query = DocumentTable::query()
			->where('COLLECTION_ID', $collectionId)
			->where('IS_MAIN', DocumentTable::IS_MAIN_YES)
			->where('IS_ARCHIVED', 'N')
			->setLimit(1)
		;
		$this->recycleBinFilter()->applyExclusion($query);

		return $query;
	}

	/**
	 * The tree of one knowledge base read for the public circuit.
	 *
	 * The listing repeats getCollectionDocuments() predicate for predicate - no archived, no
	 * recycled, no hidden main one, the same order - and differs only in the columns: the white list
	 * of the public node plus UPDATED_AT, which the node of the answer carries. TREE_SELECT is left alone on
	 * purpose: it serves the ordinary tree, and a column added there would be paid for by every
	 * caller of it, while here the answer is capped by TreeSizeLimit::MAX_NODES.
	 *
	 * Rows and not entities: the caller builds six scalars per node and never touches a Document, so
	 * hydrating one object per row was work whose result was thrown away.
	 *
	 * The listing is whole or it is not made at all: a LIMIT here would cut the rows in the order they
	 * happen to be sorted in, and that order groups children under their parent, so the cut takes the
	 * children of the last parents rather than the tail of the tree. The caller therefore counts
	 * first (countLiveInCollection) and only reads a knowledge base it has already found small enough.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getLiveTreeInCollection(int $collectionId): array
	{
		if ($collectionId <= 0)
		{
			return [];
		}

		return $this->liveInCollectionQuery($collectionId)
			->setSelect(self::PUBLIC_TREE_SELECT)
			->addOrder('PARENT_ID', 'ASC')
			->addOrder('POSITION', 'DESC')
			->addOrder('ID', 'DESC')
			->fetchAll()
		;
	}

	/**
	 * How many live documents one knowledge base holds, by the same predicate the listings use.
	 *
	 * The cheap question asked before the expensive one: the count needs neither the columns nor the
	 * ordering, so above the budget of an answer nothing is sorted, hydrated or carried into PHP at
	 * all.
	 */
	public function countLiveInCollection(int $collectionId): int
	{
		if ($collectionId <= 0)
		{
			return 0;
		}

		$row = $this->liveInCollectionQuery($collectionId)
			->registerRuntimeField(new ExpressionField('CNT', 'COUNT(*)'))
			->setSelect(['CNT'])
			->fetch()
		;

		return $row === false ? 0 : (int)$row['CNT'];
	}

	/**
	 * @return Document[]
	 */

	public function getCollectionDocuments(int $collectionId, bool $insideDeletedCollection = false): array
	{
		$query = DocumentTable::query()
			->setSelect(self::TREE_SELECT)
			->where('COLLECTION_ID', $collectionId)
			->where('IS_ARCHIVED', 'N')
			->addOrder('PARENT_ID', 'ASC')
			->addOrder('POSITION', 'DESC')
			->addOrder('ID', 'DESC')
		;
		$this->applyBinVisibility($query, $insideDeletedCollection);
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
		bool $insideDeletedCollection = false,
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

		$this->applyBinVisibility($query, $insideDeletedCollection);
		$this->mainDocumentFilter()->applyExclusion($query);

		return $query->fetchCollection()->getAll();
	}

	public function getDocumentMetaByParent(
		int $collectionId,
		?int $parentId,
		?int $limit = null,
		?int $afterPosition = null,
		?int $afterId = null,
		bool $insideDeletedCollection = false,
	): array
	{
		$query = $this->buildParentQuery($collectionId, $parentId, true, $insideDeletedCollection)
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

	/**
	 * Sort weights of a branch, heaviest first - the two columns and nothing else.
	 *
	 * Same rows getDocumentMetaByParent() answers with, and deliberately not the same query: whoever
	 * only needs to place a number among its neighbours has no use for eleven columns and a hydrated
	 * entity per sibling, and a branch can be thousands of rows wide.
	 *
	 * @return array<int, array{id: int, position: int}>
	 */
	public function getBranchPositions(int $collectionId, ?int $parentId): array
	{
		$rows = $this->buildParentQuery($collectionId, $parentId, true)
			->setSelect(['ID', 'POSITION'])
			->setCacheTtl(self::ORM_CACHE_TTL)
			->fetchAll()
		;

		return array_map(static fn(array $row): array => [
			'id' => (int)$row['ID'],
			'position' => (int)$row['POSITION'],
		], $rows);
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
	 * Full walk up PARENT_ID to the root, with NO archived or
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
	 * Batch form of getAncestorIds(): walks PARENT_ID level by level for a whole
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

		// A probe, not a count: the answer is a yes or a no, and counting to it read every child of
		// every parent asked about. Grouped by PARENT_ID with nothing to aggregate, the branch index
		// (COLLECTION_ID, PARENT_ID) is walked group by group - one entry per parent that has any -
		// instead of row by row. How many lie inside is a different question, asked by the card of a
		// document through {@see getChildrenCountMapByParentIds()}.
		$query = DocumentTable::query()
			->setSelect(['PARENT_ID'])
			->where('COLLECTION_ID', $collectionId)
			->where('IS_ARCHIVED', 'N')
			->whereIn('PARENT_ID', $normalizedIds)
			->setGroup(['PARENT_ID'])
			->setCacheTtl(self::ORM_CACHE_TTL)
		;
		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);

		$withChildren = [];
		foreach ($query->fetchAll() as $row)
		{
			$withChildren[(int)($row['PARENT_ID'] ?? 0)] = true;
		}

		$result = [];
		foreach ($normalizedIds as $id)
		{
			$result[$id] = isset($withChildren[$id]);
		}

		return $result;
	}

	/**
	 * Live direct children of each of the given documents, within one knowledge base.
	 *
	 * A real COUNT: the number reaches the card of a document as "how many lie inside", and a probe
	 * answering 1 to any branch made every card say one. The count is no dearer than the probe was -
	 * both read the branch index by (COLLECTION_ID, PARENT_ID), a grouping instead of a DISTINCT.
	 *
	 * @param int[] $parentIds
	 * @return array<int, int> parentId => live children
	 */
	public function getChildrenCountMapByParentIds(
		int $collectionId,
		array $parentIds,
		bool $insideDeletedCollection = false,
	): array
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
			->registerRuntimeField(
				'CHILD_COUNT',
				new ExpressionField('CHILD_COUNT', 'COUNT(%s)', 'ID'),
			)
			->setSelect(['PARENT_ID', 'CHILD_COUNT'])
			->where('COLLECTION_ID', $collectionId)
			->where('IS_ARCHIVED', 'N')
			->whereIn('PARENT_ID', $normalizedParentIds)
			->setCacheTtl(self::ORM_CACHE_TTL)
		;
		$this->applyBinVisibility($query, $insideDeletedCollection);
		$this->mainDocumentFilter()->applyExclusion($query);
		$query = $query->exec();

		$result = [];
		while ($row = $query->fetch())
		{
			$parentId = (int)($row['PARENT_ID'] ?? 0);
			if ($parentId > 0)
			{
				$result[$parentId] = (int)($row['CHILD_COUNT'] ?? 0);
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

	/**
	 * A deleted knowledge base is read whole: its page shows everything it holds, in the bin or not.
	 * Only a live base hides what lies in the bin, because there the bin is a place of its own the
	 * reader can go to.
	 */
	private function applyBinVisibility(Query $query, bool $insideDeletedCollection): void
	{
		if (!$insideDeletedCollection)
		{
			$this->recycleBinFilter()->applyExclusion($query);
		}
	}

	public function getChildrenCountMap(int $collectionId, bool $insideDeletedCollection = false): array
	{
		$query = DocumentTable::query()
			->setSelect(['PARENT_ID'])
			->where('COLLECTION_ID', $collectionId)
			->where('IS_ARCHIVED', 'N')
			->whereNotNull('PARENT_ID')
			->setDistinct()
		;
		$this->applyBinVisibility($query, $insideDeletedCollection);
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
		bool $onlyArchived = false,
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
			if ($onlyArchived)
			{
				$query->where('IS_ARCHIVED', 'Y');
			}
			if (!$includeRecycleBin)
			{
				$this->recycleBinFilter()->applyExclusion($query);
			}
			// Outside the branch above on purpose: the description of a knowledge base lives outside
			// the tree and is never part of anybody's subtree. It used to be dropped only when the bin
			// was excluded, so a walk of a trashed branch - which is exactly what a branch operation
			// walks - swept it in.
			$this->mainDocumentFilter()->applyExclusion($query);
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
			// Same reason as in the single-root walk: the knowledge base description is not a node.
			$this->mainDocumentFilter()->applyExclusion($query);
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

	/**
	 * Every document of a knowledge base, the archive and the recycle bin included — the set that
	 * travels when the base itself moves. Only the main document is left out: it carries the
	 * collection's description and has no place in the tree.
	 *
	 * @return int[]
	 */
	public function getCollectionDocumentIds(int $collectionId): array
	{
		if ($collectionId <= 0)
		{
			return [];
		}

		$query = DocumentTable::query()
			->setSelect(['ID'])
			->where('COLLECTION_ID', $collectionId)
		;
		$this->mainDocumentFilter()->applyExclusion($query);

		return array_map(static fn(array $row): int => (int)$row['ID'], $query->fetchAll());
	}

	/**
	 * Hands a whole set of documents over to another knowledge base in one statement. Nothing else
	 * moves with them: PARENT_ID, positions and the ACL reconciliation are the caller's business,
	 * and the caller runs this inside the transaction they all share.
	 *
	 * @param int[] $ids
	 */
	public function moveByIdsToCollection(array $ids, int $targetCollectionId): void
	{
		if ($targetCollectionId <= 0)
		{
			return;
		}

		$this->updateMulti($ids, ['COLLECTION_ID' => $targetCollectionId]);
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
	 * @param int[]|null $candidateIds null means no narrowing by id
	 * @return int[]
	 */
	public function findLiveIdsByFilter(array $filter, ?array $candidateIds, int $limit): array
	{
		if ($candidateIds === [] || $limit <= 0)
		{
			return [];
		}

		$query = DocumentTable::query()
			->setSelect(['ID'])
			->setOrder(['ID' => 'DESC'])
			->setLimit($limit)
			->where('IS_ARCHIVED', 'N')
		;
		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);

		if ($candidateIds !== null)
		{
			$ids = $this->normalizeIdList($candidateIds);
			if ($ids === [])
			{
				return [];
			}

			$query->whereIn('ID', $ids);
		}

		if ($filter !== [])
		{
			$query->addFilter(null, $filter);
		}

		return array_map('intval', array_column($query->fetchAll(), 'ID'));
	}

	/**
	 * The live documents of a knowledge base authored by one user — the set the "My articles" bulk
	 * operations run on. Resolved on the server and never taken from the client: the exclusions of
	 * {@see getLiveCollectionDocumentIds()} plus the author.
	 *
	 * @return int[]
	 */
	public function getLiveOwnedCollectionDocumentIds(int $collectionId, int $userId): array
	{
		if ($collectionId <= 0 || $userId <= 0)
		{
			return [];
		}

		$rows = $this->liveOwnedInCollectionQuery($collectionId, $userId)
			->setSelect(['ID'])
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * How many documents {@see getLiveOwnedCollectionDocumentIds()} would return, by the same
	 * predicate and without carrying any of them into PHP.
	 */
	public function countLiveOwnedCollectionDocuments(int $collectionId, int $userId): int
	{
		if ($collectionId <= 0 || $userId <= 0)
		{
			return 0;
		}

		$row = $this->liveOwnedInCollectionQuery($collectionId, $userId)
			->registerRuntimeField(new ExpressionField('CNT', 'COUNT(*)'))
			->setSelect(['CNT'])
			->fetch()
		;

		return $row === false ? 0 : (int)$row['CNT'];
	}

	private function liveOwnedInCollectionQuery(int $collectionId, int $userId): Query
	{
		return $this->liveInCollectionQuery($collectionId)->where('CREATED_BY', $userId);
	}

	/**
	 * PARENT_ID of the asked nodes and of nothing else. Enough for the boundary re-hang, where the
	 * walk only ever climbs through nodes of the set itself; a walk that passes through nodes outside
	 * it needs {@see getAncestorIdsMap()} instead.
	 *
	 * @param int[] $documentIds
	 * @return array<int, ?int> documentId => parentId, null for a root of the knowledge base;
	 *                          an id of another collection or of no document at all is absent
	 */
	public function getParentMap(int $collectionId, array $documentIds): array
	{
		$normalizedIds = $this->normalizeIdList($documentIds);
		if ($collectionId <= 0 || empty($normalizedIds))
		{
			return [];
		}

		$rows = DocumentTable::query()
			->setSelect(['ID', 'PARENT_ID'])
			->where('COLLECTION_ID', $collectionId)
			->whereIn('ID', $normalizedIds)
			->fetchAll()
		;

		$map = [];
		foreach ($rows as $row)
		{
			$parentId = $row['PARENT_ID'] === null ? null : (int)$row['PARENT_ID'];
			$map[(int)$row['ID']] = $parentId;
		}

		return $map;
	}

	/**
	 * The boundary of a set: for each of $nodeIds, the children that are NOT in the set. One query —
	 * PARENT_ID IN (...) AND ID NOT IN (...) — and only the parents that have such children appear in
	 * the answer.
	 *
	 * $liveOnly says what happens to a child that is archived or lies in the recycle bin. Archiving and
	 * deleting leave the node in its own knowledge base and have no business touching its archive, so
	 * they ask for the live boundary; a move takes the node out of the base, and a non-selected archived
	 * or trashed descendant has to stay behind with the live ones, so it asks for the full one.
	 *
	 * @param int[] $nodeIds
	 * @return array<int, int[]> parentId => ids of its children outside the set
	 */
	public function getChildrenOutsideSet(int $collectionId, array $nodeIds, bool $liveOnly = true): array
	{
		$normalizedIds = $this->normalizeIdList($nodeIds);
		if ($collectionId <= 0 || empty($normalizedIds))
		{
			return [];
		}

		$query = DocumentTable::query()
			->setSelect(['ID', 'PARENT_ID'])
			->where('COLLECTION_ID', $collectionId)
			->whereIn('PARENT_ID', $normalizedIds)
			->whereNotIn('ID', $normalizedIds)
		;
		if ($liveOnly)
		{
			$query->where('IS_ARCHIVED', 'N');
			$this->recycleBinFilter()->applyExclusion($query);
		}
		// Unconditional: the main document is never anybody's child, and the two modes differ only in
		// what they say about the archive and the bin.
		$this->mainDocumentFilter()->applyExclusion($query);

		$childrenByParent = [];
		foreach ($query->fetchAll() as $row)
		{
			$parentId = (int)($row['PARENT_ID'] ?? 0);
			if ($parentId > 0)
			{
				$childrenByParent[$parentId][] = (int)$row['ID'];
			}
		}

		return $childrenByParent;
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private function normalizeIdList(array $ids): array
	{
		return array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
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
	/**
	 * @param bool $includeMain the main document is excluded everywhere else - it lives outside the
	 *                          tree - but the cascade into the recycle bin has to take it along, or a
	 *                          restored knowledge base comes back without its description.
	 */
	public function listByCollectionForCascadeDelete(
		int $collectionId,
		int $afterId,
		int $limit,
		bool $includeMain = false,
	): array
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
		if (!$includeMain)
		{
			$this->mainDocumentFilter()->applyExclusion($query);
		}
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

	/**
	 * Archive roots: an archived document whose parent is not archived, or has no parent.
	 * The same rule as in the bin, read from the state each side keeps - the archive keeps it on the
	 * document, the bin in the presence of its row. No stored root flag exists on either side, and
	 * one would have to be recomputed for everything archived before this release.
	 *
	 * Trashed documents stay out: they belong to the bin's locus, exactly as in the flat listing.
	 * So does everything lying in a knowledge base that is itself archived or deleted: such a base is
	 * a section of another surface, whole, and its content has no second place to stand in.
	 */
	private function queryArchivedRoots(int $collectionId): Query
	{
		return $this->queryArchivedRootsIn([$collectionId]);
	}

	/**
	 * @param int[]|null $collectionIds null asks over every knowledge base
	 */
	private function queryArchivedRootsIn(?array $collectionIds): Query
	{
		$query = DocumentTable::query()
			->where('IS_ARCHIVED', 'Y')
			->whereNotNull('ARCHIVED_AT')
			->where(Query::filter()->logic('or')
				->whereNull('PARENT_ID')
				->where('PARENT.IS_ARCHIVED', 'N')
			)
		;
		if ($collectionIds !== null)
		{
			$query->whereIn('COLLECTION_ID', $collectionIds);
		}
		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);

		return $query;
	}

	/**
	 * Archive roots of a reader who does not hold the knowledge base and got in by a grant of their
	 * own. The same surface, a different starting point: what they may see begins at the documents
	 * granted to them, not at the top of the archived subtree, so a granted document is a root here
	 * even when its own parent is archived beside it.
	 *
	 * A grant is inherited downwards, so a granted document standing under another granted one is not
	 * a root - it is already inside the section, and naming it twice would show the same card at two
	 * levels. Knowledge bases the reader does hold are excluded: their archive is read the ordinary
	 * way, and counting a base twice would put two sections with one name on the screen.
	 *
	 * @param string[] $codesPersonal access codes without '*'
	 * @param int[] $heldCollectionIds bases the reader holds VIEW+ on, read by the ordinary pass
	 * @param int[]|null $onlyCollectionIds narrows the answer to these bases
	 */
	private function queryGrantedArchivedRootsIn(
		array $codesPersonal,
		array $heldCollectionIds,
		?array $onlyCollectionIds = null,
	): ?Query
	{
		if (empty($codesPersonal))
		{
			return null;
		}

		$query = DocumentTable::query()
			->where('IS_ARCHIVED', 'Y')
			->whereNotNull('ARCHIVED_AT')
			->where(DocumentAccessService::buildDocumentGrantFilter('ID', $codesPersonal))
			->where(Query::filter()->logic('or')
				->whereNull('PARENT_ID')
				->where('PARENT.IS_ARCHIVED', 'N')
				->where(DocumentAccessService::buildDocumentGrantFilter('PARENT_ID', $codesPersonal)->negative())
			)
		;

		$held = $this->normalizePositiveIds($heldCollectionIds);
		if (!empty($held))
		{
			$query->whereNotIn('COLLECTION_ID', $held);
		}

		if ($onlyCollectionIds !== null)
		{
			$only = $this->normalizePositiveIds($onlyCollectionIds);
			if (empty($only))
			{
				return null;
			}

			$query->whereIn('COLLECTION_ID', $only);
		}

		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);

		return $query;
	}

	/**
	 * Everything the reader may see in the archive, both reaches in one query: the bases they hold
	 * and the corners of other bases they were let into. One query and not two, because the sections
	 * are ordered by the name of the base and paged by that order - two lists merged in PHP would
	 * have to agree with the database on how names compare, and they do not have to.
	 *
	 * @param int[]|null $heldCollectionIds bases the reader holds VIEW+ on; null is the whole portal
	 * @param string[] $codesPersonal access codes without '*'; empty for an administrator
	 */
	private function queryArchivedRootsFor(?array $heldCollectionIds, array $codesPersonal): ?Query
	{
		$query = DocumentTable::query()
			->where('IS_ARCHIVED', 'Y')
			->whereNotNull('ARCHIVED_AT')
		;
		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);

		// Top of an archived subtree: the ordinary root of the archive.
		$ownRoot = static fn(): ConditionTree => Query::filter()->logic('or')
			->whereNull('PARENT_ID')
			->where('PARENT.IS_ARCHIVED', 'N')
		;

		if ($heldCollectionIds === null)
		{
			return $query->where($ownRoot());
		}

		$held = $this->normalizePositiveIds($heldCollectionIds);
		$reach = Query::filter()->logic('or');
		$hasBranch = false;

		if (!empty($held))
		{
			$reach->where(Query::filter()->whereIn('COLLECTION_ID', $held)->where($ownRoot()));
			$hasBranch = true;
		}

		if (!empty($codesPersonal))
		{
			// The other reach starts lower: at the granted document itself, whatever stands above it.
			$granted = Query::filter()
				->where(DocumentAccessService::buildDocumentGrantFilter('ID', $codesPersonal))
				->where(Query::filter()->logic('or')
					->whereNull('PARENT_ID')
					->where('PARENT.IS_ARCHIVED', 'N')
					->where(DocumentAccessService::buildDocumentGrantFilter('PARENT_ID', $codesPersonal)->negative())
				)
			;
			if (!empty($held))
			{
				$granted->whereNotIn('COLLECTION_ID', $held);
			}

			$reach->where($granted);
			$hasBranch = true;
		}

		if (!$hasBranch)
		{
			return null;
		}

		return $query->where($reach);
	}

	/**
	 * @param string[] $codesPersonal
	 * @param array{changedAt: string, id: int}|null $cursor
	 * @return array<int, array{id: int, documentId: int, changedAt: string}>
	 */
	public function listGrantedArchivedRootPage(
		int $collectionId,
		array $codesPersonal,
		?array $cursor,
		int $limit,
	): array
	{
		if ($collectionId <= 0)
		{
			return [];
		}

		$query = $this->queryGrantedArchivedRootsIn($codesPersonal, [], [$collectionId]);
		if ($query === null)
		{
			return [];
		}

		$this->applyArchiveCursor($query, $cursor);

		return $this->fetchArchivePage($query, $limit);
	}

	/**
	 * Keyset over (ARCHIVED_AT DESC, ID DESC), inclusive of the given key - see the twin in
	 * {@see RecycleBinRepository} for why inclusive.
	 *
	 * @param array{changedAt: string, id: int}|null $cursor
	 */
	private function applyArchiveCursor(Query $query, ?array $cursor): void
	{
		$at = CursorDate::parse($cursor['changedAt'] ?? null);
		$id = isset($cursor['id']) ? (int)$cursor['id'] : 0;
		if ($at === null || $id <= 0)
		{
			return;
		}

		$query->where(Query::filter()->logic('or')
			->where('ARCHIVED_AT', '<', $at)
			->where(Query::filter()
				->where('ARCHIVED_AT', $at)
				->where('ID', '<=', $id)
			)
		);
	}

	/**
	 * The keyset of the archive runs on the document row itself, so 'id' and 'documentId' are the
	 * same number here; both are present so a page reads the same on either surface.
	 *
	 * @return array<int, array{id: int, documentId: int, changedAt: string}>
	 */
	private function fetchArchivePage(Query $query, int $limit): array
	{
		$rows = $query
			->setSelect(['ID', 'ARCHIVED_AT'])
			->addOrder('ARCHIVED_AT', 'DESC')
			->addOrder('ID', 'DESC')
			->setLimit(max(1, $limit))
			->fetchAll()
		;

		$out = [];
		foreach ($rows as $row)
		{
			$archivedAt = $row['ARCHIVED_AT'] ?? null;
			$out[] = [
				'id' => (int)$row['ID'],
				'documentId' => (int)$row['ID'],
				'changedAt' => $archivedAt instanceof DateTime
					? $archivedAt->format('Y-m-d H:i:s')
					: (string)($archivedAt ?? ''),
			];
		}

		return $out;
	}

	/**
	 * One page of the top level of a knowledge base's section in the archive.
	 *
	 * @param array{changedAt: string, id: int}|null $cursor
	 * @return array<int, array{id: int, documentId: int, changedAt: string}>
	 */
	public function listArchivedRootPage(int $collectionId, ?array $cursor, int $limit): array
	{
		if ($collectionId <= 0)
		{
			return [];
		}

		$query = $this->queryArchivedRoots($collectionId);
		$this->applyArchiveCursor($query, $cursor);

		return $this->fetchArchivePage($query, $limit);
	}

	/**
	 * Children of an archived node: one level, archived like the node itself.
	 *
	 * The knowledge base is named even though the node alone already says which one it is: the base
	 * is the head of IX_NOTE_DOCUMENT_STATE_BRANCH (COLLECTION_ID, IS_MAIN, IS_ARCHIVED, ARCHIVED_AT, ID,
	 * PARENT_ID), the index this very order was created for. Without it the only index the page can
	 * run on is the portal-wide one on the archive, so reading one level of five documents walks the
	 * archived rows of every base on the portal until five of them turn out to have this parent.
	 *
	 * @param array{changedAt: string, id: int}|null $cursor
	 * @return array<int, array{id: int, documentId: int, changedAt: string}>
	 */
	public function listArchivedChildPage(int $parentId, int $collectionId, ?array $cursor, int $limit): array
	{
		if ($parentId <= 0)
		{
			return [];
		}

		$query = DocumentTable::query()
			->where('PARENT_ID', $parentId)
			->where('IS_ARCHIVED', 'Y')
			->whereNotNull('ARCHIVED_AT')
		;
		if ($collectionId > 0)
		{
			$query->where('COLLECTION_ID', $collectionId);
		}
		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);
		$this->applyArchiveCursor($query, $cursor);

		return $this->fetchArchivePage($query, $limit);
	}

	/**
	 * The same level for a reader who reaches the archived node by a grant alone. A grant on a
	 * document is not a grant on what lies under it: only a subtree grant reaches the children, and
	 * it reaches them as rows of their own, so the level is read through the same grant filter as
	 * everything else this reader is shown.
	 *
	 * @param string[] $codesPersonal access codes without '*'; an empty list sees nothing
	 * @param array{changedAt: string, id: int}|null $cursor
	 * @return array<int, array{id: int, documentId: int, changedAt: string}>
	 */
	public function listGrantedArchivedChildPage(
		int $parentId,
		int $collectionId,
		array $codesPersonal,
		?array $cursor,
		int $limit,
	): array
	{
		if ($parentId <= 0 || empty($codesPersonal))
		{
			return [];
		}

		$query = DocumentTable::query()
			->where('PARENT_ID', $parentId)
			->where('IS_ARCHIVED', 'Y')
			->whereNotNull('ARCHIVED_AT')
			->where(DocumentAccessService::buildDocumentGrantFilter('ID', $codesPersonal))
		;
		if ($collectionId > 0)
		{
			$query->where('COLLECTION_ID', $collectionId);
		}
		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);
		$this->applyArchiveCursor($query, $cursor);

		return $this->fetchArchivePage($query, $limit);
	}

	/**
	 * One page of the stream of documents of the knowledge bases the reader holds, every state the
	 * caller asks for in a single order.
	 *
	 * The order (COLLECTION_ID DESC, ID DESC) is the one IX_NOTE_DOC_MAIN gives away: the two
	 * columns of its head are the equalities of the query, and the primary key rides in the suffix
	 * of every secondary index, so the ID order inside a base costs nothing and needs no sort. A
	 * page therefore costs its own length and not the number of bases behind the IN.
	 *
	 * State is an ordinary predicate over that order and not a segment of it: archived lives on the
	 * document row, trashed is the existence of a bin row on the reference over UNIQUE(DOCUMENT_ID).
	 * A page asked for the trashed state alone is read from the bin instead - see the twin in
	 * {@see RecycleBinRepository::listByCollectionsPage()}, which has an index of its own for it.
	 *
	 * @param int[]|null $collectionIds bases the reader holds VIEW+ on; null puts no restriction,
	 *                                   the empty list answers nothing
	 * @param string[] $states self::STATE_*; the empty list answers nothing
	 * @param array{collectionId: int, id: int}|null $cursor last row of the previous page, exclusive
	 * @return array<int, array{id: int, collectionId: int}>
	 */
	public function listByCollectionsPage(?array $collectionIds, array $states, ?array $cursor, int $limit): array
	{
		$ids = $collectionIds === null ? null : $this->normalizePositiveIds($collectionIds);
		if ($ids === [] || empty($states) || $limit <= 0)
		{
			return [];
		}

		$query = DocumentTable::query()
			->setSelect(['ID', 'COLLECTION_ID'])
			->addOrder('COLLECTION_ID', 'DESC')
			->addOrder('ID', 'DESC')
			->setLimit($limit)
		;
		if ($ids === null)
		{
			// Unrestricted, yet still not the whole table: a document whose base row is gone belongs
			// to the stream of orphans alone, and naming it here too would hand it out twice.
			$query->whereNotNull('COLLECTION.ID');
		}
		else
		{
			$query->whereIn('COLLECTION_ID', $ids);
		}
		$this->mainDocumentFilter()->applyExclusion($query);
		$this->applyStatePredicate($query, $states);
		$this->applyStreamCursor($query, $cursor);

		$out = [];
		foreach ($query->fetchAll() as $row)
		{
			$out[] = [
				'id' => (int)$row['ID'],
				'collectionId' => (int)$row['COLLECTION_ID'],
			];
		}

		return $out;
	}

	/**
	 * @param string[] $states
	 */
	private function applyStatePredicate(Query $query, array $states): void
	{
		$live = in_array(self::STATE_LIVE, $states, true);
		$archived = in_array(self::STATE_ARCHIVED, $states, true);
		$trashed = in_array(self::STATE_TRASHED, $states, true);

		if ($live && $archived && $trashed)
		{
			return;
		}

		$predicate = Query::filter()->logic('or');
		if ($trashed)
		{
			$predicate->whereNotNull('RECYCLE_BIN.ID');
		}

		if ($live || $archived)
		{
			// A document in the bin is trashed whatever its archive flag says, so the two states
			// that live on the row are asked for outside the bin.
			$outsideBin = Query::filter()->whereNull('RECYCLE_BIN.ID');
			if (!$live)
			{
				$outsideBin->where('IS_ARCHIVED', 'Y');
			}
			if (!$archived)
			{
				$outsideBin->where('IS_ARCHIVED', 'N');
			}

			$predicate->where($outsideBin);
		}

		$query->where($predicate);
	}

	/**
	 * Keyset over (COLLECTION_ID DESC, ID DESC), exclusive of the given key: the cursor names the
	 * last row handed out, and the stream continues strictly after it.
	 *
	 * @param array{collectionId: int, id: int}|null $cursor
	 */
	private function applyStreamCursor(Query $query, ?array $cursor): void
	{
		$collectionId = isset($cursor['collectionId']) ? (int)$cursor['collectionId'] : 0;
		$id = isset($cursor['id']) ? (int)$cursor['id'] : 0;
		if ($collectionId <= 0 || $id <= 0)
		{
			return;
		}

		$query->where(Query::filter()->logic('or')
			->where('COLLECTION_ID', '<', $collectionId)
			->where(Query::filter()
				->where('COLLECTION_ID', $collectionId)
				->where('ID', '<', $id)
			)
		);
	}

	/**
	 * A page of the archive's section list: how much every base holds, in the order the sections are
	 * read in - by name. The name lives on the other table, so it is selected and grouped by along
	 * with the id; ordering by it in SQL is what lets the list be paged at all. Ordered by the id
	 * instead, the page said "the fifty oldest bases" while the screen showed them alphabetically -
	 * two different orders, and no way to ask for what came after the page.
	 *
	 * @param array{title: string, collectionId: int}|null $afterCursor
	 * @return array<int, array{count: int, title: string}> in the order of the page
	 */
	private function tallyByCollectionName(Query $query, int $limit, ?array $afterCursor): array
	{
		$query
			->registerRuntimeField(
				'ARCHIVED_ROOT_COUNT',
				new ExpressionField('ARCHIVED_ROOT_COUNT', 'COUNT(%s)', 'ID'),
			)
			->setSelect(['COLLECTION_ID', 'COLLECTION_NAME' => 'COLLECTION.NAME', 'ARCHIVED_ROOT_COUNT'])
			->addOrder('COLLECTION_NAME', 'ASC')
			->addOrder('COLLECTION_ID', 'ASC')
			->setLimit(max(1, $limit))
		;

		// Exclusive keyset: a page of sections names the base it stops at, and repeating it on the
		// next page would draw the same section twice.
		$title = isset($afterCursor['title']) ? (string)$afterCursor['title'] : '';
		$cursorId = isset($afterCursor['collectionId']) ? (int)$afterCursor['collectionId'] : 0;
		if ($cursorId > 0)
		{
			$query->where(Query::filter()->logic('or')
				->where('COLLECTION_NAME', '>', $title)
				->where(Query::filter()
					->where('COLLECTION_NAME', $title)
					->where('COLLECTION_ID', '>', $cursorId)
				)
			);
		}

		$out = [];
		foreach ($query->fetchAll() as $row)
		{
			$collectionId = (int)($row['COLLECTION_ID'] ?? 0);
			if ($collectionId > 0)
			{
				$out[$collectionId] = [
					'count' => (int)($row['ARCHIVED_ROOT_COUNT'] ?? 0),
					'title' => (string)($row['COLLECTION_NAME'] ?? ''),
				];
			}
		}

		return $out;
	}

	/**
	 * Archived roots of every knowledge base at once - see the twin in
	 * {@see RecycleBinRepository::tallyRootsByFreshness()} for why one pass replaces a query per
	 * base.
	 *
	 * The bound applies to the bases that hold archived content, and not to a prefix of the portal's
	 * bases: cutting first and counting afterwards showed an empty archive on a portal whose archived
	 * documents happen to live in bases created later.
	 *
	 * @param int[]|null $collectionIds bases the viewer may see; null puts no restriction, the empty
	 *                                  list answers nothing
	 * @param int $limit how many bases the page holds
	 * @return array<int, array{count: int, title: string}> collectionId => archived roots and the
	 *         name the page is ordered by, in the order of the page
	 */
	public function tallyArchivedRootsByCollection(
		?array $collectionIds,
		int $limit,
		?array $afterCursor = null,
		array $codesPersonal = [],
	): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$query = $this->queryArchivedRootsFor($collectionIds, $codesPersonal);
		if ($query === null)
		{
			return [];
		}

		return $this->tallyByCollectionName($query, $limit, $afterCursor);
	}

	/**
	 * Direct archived children of each of the given documents, counted over the documents
	 * themselves rather than over the rows of the bin.
	 *
	 * @param int[] $collectionIds bases the parents live in; the empty list counts nothing
	 * @param int[] $parentIds
	 * @param ConditionTree|null $visibility predicate over ID / COLLECTION_ID; null counts everything
	 * @return array<int, int> parentId => archived children
	 */
	public function getArchivedChildCountMap(
		array $collectionIds,
		array $parentIds,
		?ConditionTree $visibility = null,
	): array
	{
		return $this->getChildCountMapByState($collectionIds, $parentIds, 'Y', $visibility);
	}

	/**
	 * Live direct children of each of the given documents, counted for real.
	 *
	 * @param int[] $collectionIds bases the parents live in; the empty list counts nothing
	 * @param int[] $parentIds
	 * @param ConditionTree|null $visibility predicate over ID / COLLECTION_ID; null counts everything
	 * @return array<int, int> parentId => live children
	 */
	public function getLiveChildCountMap(
		array $collectionIds,
		array $parentIds,
		?ConditionTree $visibility = null,
	): array
	{
		return $this->getChildCountMapByState($collectionIds, $parentIds, 'N', $visibility);
	}

	/**
	 * The knowledge base is named even though a parent id already says which one it is: every branch
	 * index of the table starts with COLLECTION_ID, and no index starts with PARENT_ID. Asked by
	 * parent alone the count ran on the portal-wide index of the state - IS_ARCHIVED and nothing
	 * else - so counting the children of one document read every document of the portal in that
	 * state and probed the bin for each of them. Named, the count runs on the branch index.
	 *
	 * A parent id belongs to exactly one base, so a batch spanning several of them stays correct
	 * with both sides given as lists: the extra bases only widen the range, they cannot add rows to
	 * another parent's count.
	 *
	 * @param int[] $collectionIds
	 * @param int[] $parentIds
	 * @return array<int, int>
	 */
	private function getChildCountMapByState(
		array $collectionIds,
		array $parentIds,
		string $isArchived,
		?ConditionTree $visibility = null,
	): array
	{
		$ids = $this->normalizePositiveIds($parentIds);
		$collections = $this->normalizePositiveIds($collectionIds);
		if (empty($ids) || empty($collections))
		{
			return [];
		}

		$query = DocumentTable::query()
			->registerRuntimeField(
				'CHILD_COUNT',
				new ExpressionField('CHILD_COUNT', 'COUNT(%s)', 'ID'),
			)
			->setSelect(['PARENT_ID', 'CHILD_COUNT'])
			->whereIn('COLLECTION_ID', $collections)
			->whereIn('PARENT_ID', $ids)
			->where('IS_ARCHIVED', $isArchived)
		;
		if ($visibility !== null)
		{
			$query->where($visibility);
		}
		$this->recycleBinFilter()->applyExclusion($query);
		$this->mainDocumentFilter()->applyExclusion($query);

		$out = [];
		foreach ($query->fetchAll() as $row)
		{
			$parentId = (int)($row['PARENT_ID'] ?? 0);
			if ($parentId > 0)
			{
				$out[$parentId] = (int)($row['CHILD_COUNT'] ?? 0);
			}
		}

		return $out;
	}

	private function buildParentQuery(
		int $collectionId,
		?int $parentId,
		bool $activeOnly,
		bool $insideDeletedCollection = false,
	): Query
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

		$this->applyBinVisibility($query, $insideDeletedCollection);
		$this->mainDocumentFilter()->applyExclusion($query);

		return $query;
	}

}

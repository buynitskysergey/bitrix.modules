<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Access;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Model\DocumentAccessTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;

/**
 * [P3.T3 / ALG-04] Reconciles the derived ACL rows of a source document R against R's CURRENT
 * target state — the set of subtree markers (rows where SOURCE_DOCUMENT_ID == R and
 * DOCUMENT_ID == R). It always converges to the live markers, never to a captured snapshot, so a
 * concurrent narrow that removed a marker wins over an in-flight widen: the widen simply stops
 * materialising the subject that is no longer in the target.
 *
 * [P4.T5] This class is ALSO the single choke-point for narrowing synchronisation across the four
 * tree events. Every entry that can strand derived rows — reparent (move / restore-with-reparent)
 * and source removal (hard-delete) — MUST route through {@see syncOnMove()} / {@see revokeSource()}.
 * Widening is fail-closed (a skip merely under-grants); narrowing is fail-open (a skip over-shares),
 * so the narrowing DELETE lives here and never in a controller.
 *
 * Split of responsibilities (mirrors the security contract):
 *   - narrowing (a subject dropped from the target)  → one addressed DELETE per subject, synchronous,
 *     atomic, no threshold. Uses IX_NOTE_DOC_ACCESS_SOURCE (SOURCE_DOCUMENT_ID, DOCUMENT_ID).
 *   - lowering (a subject's level dropped)            → one addressed UPDATE per subject, synchronous.
 *   - widening (raise level / materialise new nodes)  → idempotent UPSERT chunks; above the threshold
 *     it becomes a durable background task when a {@see SubtreeWidenDeferralSink} is injected (P4.T6).
 *
 * Idempotency: the widen path UPSERTs by the unique key (DOCUMENT_ID, SUBJECT_CODE,
 * SOURCE_DOCUMENT_ID) and never delete+reinserts, so it can be re-run or resumed without dropping
 * access for existing recipients.
 */
class SubtreeAclReconciler
{
	// Above this many derived rows (nodes x subjects) the widen pass is a candidate for the durable
	// background job (P4.T6). Narrowing and lowering ignore the threshold — they are always synchronous.
	public const WIDEN_SYNC_THRESHOLD = 500;

	// How many descendants {@see reconcile()} enumerates before deciding where the widen runs. It must
	// clear BOTH thresholds by one, and is derived from them rather than written out, because the two
	// live in different classes and are tuned independently:
	//   - WIDEN_SYNC_THRESHOLD, so the probe always settles where the widen belongs. One node per row
	//     is enough: with a single subject the threshold trips at exactly that many nodes, with more
	//     subjects it trips earlier.
	//   - REALTIME_BATCH_THRESHOLD, so a truncated probe reliably reads as "too many to list" and the
	//     push degrades to a refetch. Were the probe to come back at or below it, the caller would
	//     mistake a partial id list for the whole subtree and cascade it as such.
	private const WIDEN_PROBE_LIMIT = self::WIDEN_SYNC_THRESHOLD > PushNotificationService::REALTIME_BATCH_THRESHOLD
		? self::WIDEN_SYNC_THRESHOLD + 1
		: PushNotificationService::REALTIME_BATCH_THRESHOLD + 1;

	private const INSERT_CHUNK = 500;

	// Bounds the IN-list of an addressed DELETE/SELECT over a moved subtree.
	private const ID_CHUNK = 500;

	private DocumentRepository $documentRepository;
	private ?SubtreeWidenDeferralSink $deferralSink;

	public function __construct(
		?DocumentRepository $documentRepository = null,
		?SubtreeWidenDeferralSink $deferralSink = null
	)
	{
		$this->documentRepository = $documentRepository ?? new DocumentRepository();
		$this->deferralSink = $deferralSink;
	}

	/**
	 * Reconcile derived rows of source $rootId over its subtree.
	 *
	 * @return int[] descendant document ids in the subtree (root excluded) that were considered —
	 *               reused by the caller for the cascade push fan-out.
	 */
	public function reconcile(int $rootId, int $collectionId): array
	{
		if ($rootId <= 0 || $collectionId <= 0)
		{
			return [];
		}

		// target := current marker rows keyed by subject => level (positive levels only; a marker can
		// never legitimately carry NONE because "none + subtree" is rejected up-front).
		$target = $this->loadTarget($rootId);

		// narrowing + lowering to the current target — always synchronous.
		$this->synchroniseRemovals($rootId, $target);

		// Bounded probe, not the whole subtree: the only question here is whether the widen belongs in
		// the background, and WIDEN_PROBE_LIMIT descendants always answer it (see the constant). A
		// request that shares a branch with ten thousand nodes under it must not walk all ten thousand
		// just to hand the work over.
		$descendantIds = $this->descendantIdsForSource($rootId, $collectionId, self::WIDEN_PROBE_LIMIT);
		$probeTruncated = count($descendantIds) >= self::WIDEN_PROBE_LIMIT;

		if (empty($target) || empty($descendantIds))
		{
			return $descendantIds;
		}

		// --- widening: materialise / raise derived rows to the current target across the subtree. ---
		$rowCount = count($descendantIds) * count($target);
		if ($rowCount > self::WIDEN_SYNC_THRESHOLD && $this->widenDeferred($rootId, $collectionId, $target, $descendantIds))
		{
			// A durable background job (P4.T6) took ownership of the widen pass.
			return $descendantIds;
		}

		if ($probeTruncated)
		{
			// Nobody took the work: this stays a synchronous P3 pass, which needs the real subtree.
			$descendantIds = $this->descendantIdsForSource($rootId, $collectionId);
		}

		$this->widen($rootId, $target, $descendantIds);

		return $descendantIds;
	}

	/**
	 * [P4.T2 / P4.T5] Reparent choke-point. After $movedId's PARENT_ID/COLLECTION_ID have been
	 * updated, re-align the derived rows of its whole subtree:
	 *   - NARROW (synchronous, atomic): drop derived rows pushed by ancestor sources that no longer
	 *     cover the subtree. Internal markers (source inside the subtree) and still-covering ancestors
	 *     are preserved.
	 *   - WIDEN (idempotent, threshold-aware): (re)materialise every ancestor source that now covers
	 *     the moved node onto the subtree.
	 *
	 * MUST be called inside the move transaction so the narrowing DELETE is atomic with the reparent.
	 * Covering sources are read from the (unmoved) target-parent chain rather than by re-reading the
	 * just-updated moved row, so a stale ORM row cache on the moved node cannot mislead the sync.
	 *
	 * @param int[] $subtreeIds moved node + its descendants (ids are stable across the reparent).
	 */
	public function syncOnMove(int $movedId, ?int $targetParentId, int $collectionId, array $subtreeIds): void
	{
		$this->syncOnMoveMany([$movedId => $subtreeIds], $targetParentId, $collectionId);
	}

	/**
	 * [P4.T5] Batch form of {@see syncOnMove()} for the reparent of several siblings onto ONE parent
	 * (the archive/trash re-hang). Same contract, same transaction requirement — it only removes the
	 * work that is identical for every moved node: the covering chain of the shared target parent is
	 * read once, and each covering source is materialised over the union of the subtrees, so its
	 * target is loaded once instead of once per sibling.
	 *
	 * The narrowing pass stays per moved node: what counts as an "internal" source is defined by that
	 * node's own subtree, and merging the sets would widen {@see revokeExternalDerivedRows()}'s keep
	 * list beyond what each subtree actually vouches for.
	 *
	 * @param array<int, int[]> $subtreeIdsByMovedId movedId => moved node + its descendants
	 */
	public function syncOnMoveMany(array $subtreeIdsByMovedId, ?int $targetParentId, int $collectionId): void
	{
		if ($collectionId <= 0 || empty($subtreeIdsByMovedId))
		{
			return;
		}

		// Markers on the target-parent chain (inclusive) — the sources that legitimately cover the node.
		$coveringSources = $this->collectCoveringSourceIdsUnderParent($targetParentId);

		$allNodeIds = [];
		foreach ($subtreeIdsByMovedId as $movedId => $subtreeIds)
		{
			$subtreeIds = $this->normalizeIds($subtreeIds);
			if ((int)$movedId <= 0 || empty($subtreeIds))
			{
				continue;
			}

			// Narrow first: sources that stopped covering must lose their derived rows on the subtree
			// before (or regardless of) any widen — a skipped narrow over-shares.
			$this->revokeExternalDerivedRows($subtreeIds, $coveringSources);

			foreach ($subtreeIds as $nodeId)
			{
				$allNodeIds[$nodeId] = true;
			}
		}

		if (empty($allNodeIds))
		{
			return;
		}

		$nodeIds = array_keys($allNodeIds);
		foreach ($coveringSources as $sourceId)
		{
			$this->materialiseSourceOntoNodes($sourceId, $collectionId, $nodeIds);
		}
	}

	/**
	 * [P4.T1] Materialise every ACTIVE covering source's target onto a freshly created node (widening
	 * only; fail-closed at the call site). Covering sources are ancestor markers on the node's path to
	 * root (an archived ancestor breaks the chain — no new inheritance under a soft-archived source).
	 */
	public function materialiseForNewNode(int $documentId, int $collectionId): void
	{
		if ($documentId <= 0 || $collectionId <= 0)
		{
			return;
		}

		foreach ($this->collectCoveringSourceIds($documentId) as $sourceId)
		{
			$this->materialiseSourceOntoNodes($sourceId, $collectionId, [$documentId]);
		}
	}

	/**
	 * [P4.T4 / P4.T5] Revoke ALL derived rows pushed by $sourceId across its former subtree (source
	 * removed / hard-deleted). Addressed by SOURCE_DOCUMENT_ID — descendant rows are NOT reachable by
	 * deleting the source document's own id, so this is a distinct cascade step. Also sweeps the
	 * source's own marker row (source == document) harmlessly.
	 */
	public function revokeSource(int $sourceId): void
	{
		$this->revokeSources([$sourceId]);
	}

	/**
	 * [P4.T4 / P4.T5] Batch variant of {@see revokeSource()} for a hard-delete cascade that removes a
	 * whole subtree at once: any deleted document may itself be a source, so its dangling derived rows
	 * across the (former) subtree are swept in id-bounded chunks.
	 *
	 * @param int[] $sourceIds
	 */
	public function revokeSources(array $sourceIds): void
	{
		$sourceIds = $this->normalizeIds($sourceIds);
		if (empty($sourceIds))
		{
			return;
		}

		foreach (array_chunk($sourceIds, self::ID_CHUNK) as $chunk)
		{
			DocumentAccessTable::deleteByFilter(['@SOURCE_DOCUMENT_ID' => $chunk]);
		}
	}

	/**
	 * [P4.T2] Escalation predicate for the move/restore moderate-gate. Would placing a subtree under
	 * $targetParentId hand a positive DERIVED level to any subject that does not already have at least
	 * that effective level on some node (including a node whose only local row is an explicit none)?
	 *
	 * Pure read, evaluated BEFORE the reparent commits. Returns false when the target branch has no
	 * covering source (an ordinary move never escalates) — so a plain drag-n-drop is never gated.
	 *
	 * @param int[] $subtreeIds moved node + its descendants
	 */
	public function movementEscalatesAccess(?int $targetParentId, array $subtreeIds): bool
	{
		$subtreeIds = $this->normalizeIds($subtreeIds);
		if (empty($subtreeIds))
		{
			return false;
		}

		$coveringSources = $this->collectCoveringSourceIdsUnderParent($targetParentId);
		if (empty($coveringSources))
		{
			return false;
		}

		// subject => the strongest positive level the covering sources would push down.
		$coveringLevels = [];
		foreach ($coveringSources as $sourceId)
		{
			foreach ($this->loadTarget($sourceId) as $subjectCode => $level)
			{
				$coveringLevels[$subjectCode] = max($coveringLevels[$subjectCode] ?? 0, $level);
			}
		}
		if (empty($coveringLevels))
		{
			return false;
		}

		$subjects = array_keys($coveringLevels);
		$rowsByNodeSubject = $this->loadEffectiveRows($subtreeIds, $subjects);

		foreach ($coveringLevels as $subjectCode => $coveringLevel)
		{
			foreach ($subtreeIds as $nodeId)
			{
				$rows = $rowsByNodeSubject[$nodeId][$subjectCode] ?? [];
				if (DocumentAccessService::reduceDocumentLevel($rows) < $coveringLevel)
				{
					// At least one node gains access it did not effectively have — real escalation.
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * [P4.T6] Synchronous narrowing + lowering to the current target, with NO widen. Called by the
	 * durable agent at the start of a source's chunked widen so removals are applied exactly once.
	 * Safe to re-run.
	 *
	 * @param array<string, int>|null $target pre-loaded target; loaded on demand when null.
	 */
	public function synchroniseRemovals(int $rootId, ?array $target = null): void
	{
		if ($rootId <= 0)
		{
			return;
		}

		$target ??= $this->loadTarget($rootId);

		// subjects that currently have derived rows anywhere in the tree (the previous materialisation).
		$dropped = array_values(array_filter(
			$this->loadMaterialisedSubjects($rootId),
			static fn(string $subjectCode): bool => !isset($target[$subjectCode]),
		));
		if (!empty($dropped))
		{
			$this->deleteDerivedForSubjects($rootId, $dropped);
		}

		// Grouped by level, not one statement per subject: this runs on every agent tick, and the
		// distinct levels are the handful of DocumentAccessService constants no matter how many
		// subjects the source grants to.
		$subjectsByLevel = [];
		foreach ($target as $subjectCode => $level)
		{
			$subjectsByLevel[(int)$level][] = (string)$subjectCode;
		}

		foreach ($subjectsByLevel as $level => $subjectCodes)
		{
			$this->lowerDerivedForSubjects($rootId, $subjectCodes, $level);
		}
	}

	/**
	 * [P4.T6] Public target read used by the agent.
	 *
	 * @return array<string, int> subject => level (positive only)
	 */
	public function targetForSource(int $rootId): array
	{
		return $this->loadTarget($rootId);
	}

	/**
	 * [P4.T6] Subtree descendant ids of a source (root excluded), sorted ascending so the agent can
	 * chunk by a cursor on the id.
	 *
	 * The walk itself cannot be paginated by that cursor: children are reachable only through their
	 * parents, so reaching a node above the cursor still means traversing everything below it. The
	 * cursor resumes the WRITE pass, not the read. $limit is the one available bound, and it only
	 * suits callers that need a count comparison rather than the ids themselves.
	 *
	 * @param int|null $limit stop after this many descendants.
	 * @return int[]
	 */
	public function descendantIdsForSource(int $rootId, int $collectionId, ?int $limit = null): array
	{
		$ids = array_values(array_filter(
			array_map('intval', $this->documentRepository->getSubtreeIds(
				$rootId,
				$collectionId,
				false,
				false,
				$limit === null ? null : $limit + 1, // + the root, which getSubtreeIds counts and we drop
			)),
			static fn(int $id): bool => $id !== $rootId && $id > 0,
		));
		sort($ids, SORT_NUMERIC);

		return $ids;
	}

	/**
	 * [P4.T6] Public, idempotent widen of a source's target onto an explicit chunk of nodes. The agent
	 * drives this per cursor chunk; $rootId is excluded defensively (its own row is the marker).
	 *
	 * @param array<string, int> $target subject => level
	 * @param int[] $nodeIds
	 */
	public function widenNodes(int $rootId, array $target, array $nodeIds): void
	{
		$nodeIds = array_values(array_filter(
			$this->normalizeIds($nodeIds),
			static fn(int $id): bool => $id !== $rootId,
		));
		if (empty($target) || empty($nodeIds))
		{
			return;
		}

		$this->widen($rootId, $target, $nodeIds);
	}

	/**
	 * Extension point (P4.T6): when the widen set exceeds WIDEN_SYNC_THRESHOLD and a deferral sink was
	 * injected, the source is enqueued for the durable background job and the synchronous widen is
	 * skipped (return true). Without a sink everything stays synchronous — this preserves the P3
	 * default that direct callers rely on.
	 *
	 * @param array<string, int> $target subject => level
	 * @param int[] $descendantIds
	 */
	protected function widenDeferred(int $rootId, int $collectionId, array $target, array $descendantIds): bool
	{
		if ($this->deferralSink === null)
		{
			return false;
		}

		$this->deferralSink->enqueue($rootId, $collectionId);

		return true;
	}

	/**
	 * Widen one source onto the given nodes, honouring the deferral threshold.
	 *
	 * @param int[] $nodeIds
	 */
	private function materialiseSourceOntoNodes(int $sourceId, int $collectionId, array $nodeIds): void
	{
		$target = $this->loadTarget($sourceId);
		if (empty($target))
		{
			return;
		}

		$nodeIds = array_values(array_filter(
			$this->normalizeIds($nodeIds),
			static fn(int $id): bool => $id !== $sourceId,
		));
		if (empty($nodeIds))
		{
			return;
		}

		$rowCount = count($nodeIds) * count($target);
		if ($rowCount > self::WIDEN_SYNC_THRESHOLD && $this->widenDeferred($sourceId, $collectionId, $target, $nodeIds))
		{
			return;
		}

		$this->widen($sourceId, $target, $nodeIds);
	}

	/**
	 * Narrowing for a reparent: drop derived rows on $subtreeIds whose source neither sits inside the
	 * subtree (an internal marker travels with the tree) nor still covers it. Addressed per dropped
	 * source, so it rides IX_NOTE_DOC_ACCESS_SOURCE.
	 *
	 * @param int[] $subtreeIds
	 * @param int[] $coveringSources ancestor sources that still cover the subtree
	 */
	private function revokeExternalDerivedRows(array $subtreeIds, array $coveringSources): void
	{
		$subtreeIds = $this->normalizeIds($subtreeIds);
		if (empty($subtreeIds))
		{
			return;
		}

		$keep = array_fill_keys($subtreeIds, true);
		foreach ($coveringSources as $sourceId)
		{
			$keep[(int)$sourceId] = true;
		}

		foreach (array_chunk($subtreeIds, self::ID_CHUNK) as $chunk)
		{
			// Distinct external sources currently pushing rows onto this chunk.
			$present = DocumentAccessTable::query()
				->setSelect(['SOURCE_DOCUMENT_ID'])
				->whereIn('DOCUMENT_ID', $chunk)
				->where('SOURCE_DOCUMENT_ID', '!=', DocumentAccessService::SOURCE_NONE)
				->setDistinct()
				->exec()
			;

			$droppedSources = [];
			while ($row = $present->fetch())
			{
				$sourceId = (int)$row['SOURCE_DOCUMENT_ID'];
				if ($sourceId > 0 && !isset($keep[$sourceId]))
				{
					$droppedSources[] = $sourceId;
				}
			}

			foreach ($droppedSources as $sourceId)
			{
				DocumentAccessTable::deleteByFilter([
					'=SOURCE_DOCUMENT_ID' => $sourceId,
					'@DOCUMENT_ID' => $chunk,
				]);
			}
		}
	}

	/**
	 * Active covering sources of $documentId: ancestor markers on its path to root. getDocumentPathToRoot
	 * stops at an archived ancestor or a collection boundary, so an archived source is not "active".
	 *
	 * @return int[]
	 */
	public function collectCoveringSourceIds(int $documentId): array
	{
		if ($documentId <= 0)
		{
			return [];
		}

		$ancestorIds = array_map(
			static fn($document): int => (int)$document->getId(),
			$this->documentRepository->getDocumentPathToRoot($documentId),
		);

		return $this->markersAmong($ancestorIds);
	}

	/**
	 * Covering sources for a node that would be placed directly under $parentId: markers on the chain
	 * from $parentId upward, INCLUSIVE (the parent itself may be a source).
	 *
	 * @return int[]
	 */
	private function collectCoveringSourceIdsUnderParent(?int $parentId): array
	{
		if ($parentId === null || $parentId <= 0)
		{
			return [];
		}

		// An archived ancestor breaks the inheritance chain, and the parent itself is an ancestor.
		// getDocumentPathToRoot() applies that rule from the parent UPWARD but never to the parent, so
		// without this check a soft-archived parent would still push its own marker down.
		$parent = $this->documentRepository->getMetaById($parentId, ['ID', 'IS_ARCHIVED']);
		if ($parent === null || $parent->getIsArchived())
		{
			return [];
		}

		$candidateIds = [$parentId];
		foreach ($this->documentRepository->getDocumentPathToRoot($parentId) as $ancestor)
		{
			$candidateIds[] = (int)$ancestor->getId();
		}

		return $this->markersAmong($candidateIds);
	}

	/**
	 * @param int[] $ids
	 * @return int[] ids among $ids that carry a subtree marker (SOURCE_DOCUMENT_ID == DOCUMENT_ID)
	 */
	private function markersAmong(array $ids): array
	{
		$ids = $this->normalizeIds($ids);
		if (empty($ids))
		{
			return [];
		}

		$result = DocumentAccessTable::query()
			->setSelect(['DOCUMENT_ID'])
			->whereIn('DOCUMENT_ID', $ids)
			->whereColumn('SOURCE_DOCUMENT_ID', '=', 'DOCUMENT_ID')
			->where('SUBJECT_CODE', '!=', '*')
			->setDistinct()
			->exec()
		;

		$markers = [];
		while ($row = $result->fetch())
		{
			$markers[] = (int)$row['DOCUMENT_ID'];
		}

		return $markers;
	}

	/**
	 * @param int[] $documentIds
	 * @param string[] $subjectCodes
	 * @return array<int, array<string, array<int, array{level: int, source: int}>>>
	 *         documentId => subjectCode => list of {level, source}
	 */
	private function loadEffectiveRows(array $documentIds, array $subjectCodes): array
	{
		$documentIds = $this->normalizeIds($documentIds);
		$subjectCodes = array_values(array_filter(
			$subjectCodes,
			static fn($code): bool => is_string($code) && $code !== '' && $code !== '*',
		));
		if (empty($documentIds) || empty($subjectCodes))
		{
			return [];
		}

		$byNodeSubject = [];
		foreach (array_chunk($documentIds, self::ID_CHUNK) as $chunk)
		{
			$rows = DocumentAccessTable::query()
				->setSelect(['DOCUMENT_ID', 'SUBJECT_CODE', 'LEVEL', 'SOURCE_DOCUMENT_ID'])
				->whereIn('DOCUMENT_ID', $chunk)
				->whereIn('SUBJECT_CODE', $subjectCodes)
				->fetchAll();

			foreach ($rows as $row)
			{
				$documentId = (int)$row['DOCUMENT_ID'];
				$subjectCode = (string)$row['SUBJECT_CODE'];
				$byNodeSubject[$documentId][$subjectCode][] = [
					'level' => (int)$row['LEVEL'],
					'source' => (int)$row['SOURCE_DOCUMENT_ID'],
				];
			}
		}

		return $byNodeSubject;
	}

	/**
	 * @return array<string, int> subject => level (positive only)
	 */
	private function loadTarget(int $rootId): array
	{
		$rows = DocumentAccessTable::query()
			->setSelect(['SUBJECT_CODE', 'LEVEL'])
			->where('DOCUMENT_ID', $rootId)
			->where('SOURCE_DOCUMENT_ID', $rootId)
			->where('SUBJECT_CODE', '!=', '*')
			->fetchAll();

		$target = [];
		foreach ($rows as $row)
		{
			$level = (int)$row['LEVEL'];
			if ($level > DocumentAccessService::LEVEL_NONE)
			{
				$target[(string)$row['SUBJECT_CODE']] = $level;
			}
		}

		return $target;
	}

	/**
	 * @return string[] distinct subject codes that currently have derived rows for this source
	 */
	private function loadMaterialisedSubjects(int $rootId): array
	{
		$rows = DocumentAccessTable::query()
			->setSelect(['SUBJECT_CODE'])
			->where('SOURCE_DOCUMENT_ID', $rootId)
			->where('DOCUMENT_ID', '!=', $rootId)
			->setDistinct()
			->fetchAll();

		return array_values(array_unique(array_map(
			static fn(array $row): string => (string)$row['SUBJECT_CODE'],
			$rows,
		)));
	}

	/**
	 * @param string[] $subjectCodes
	 */
	private function deleteDerivedForSubjects(int $rootId, array $subjectCodes): void
	{
		foreach (array_chunk($subjectCodes, self::ID_CHUNK) as $chunk)
		{
			DocumentAccessTable::deleteByFilter([
				'=SOURCE_DOCUMENT_ID' => $rootId,
				'!=DOCUMENT_ID' => $rootId,
				'@SUBJECT_CODE' => $chunk,
			]);
		}
	}

	/**
	 * Rows above the target level are read first and then updated by primary key: the ORM has no
	 * update-by-filter, and updateMulti() collapses an identical payload into a single statement.
	 * The read is normally empty — a lowering is the rare case, an unchanged level matches nothing.
	 *
	 * @param string[] $subjectCodes subjects that share $targetLevel
	 */
	private function lowerDerivedForSubjects(int $rootId, array $subjectCodes, int $targetLevel): void
	{
		if (empty($subjectCodes))
		{
			return;
		}

		$ids = [];
		foreach (array_chunk($subjectCodes, self::ID_CHUNK) as $chunk)
		{
			$rows = DocumentAccessTable::query()
				->setSelect(['ID'])
				->where('SOURCE_DOCUMENT_ID', $rootId)
				->where('DOCUMENT_ID', '!=', $rootId)
				->whereIn('SUBJECT_CODE', $chunk)
				->where('LEVEL', '>', $targetLevel)
				->exec()
			;

			while ($row = $rows->fetch())
			{
				$ids[] = (int)$row['ID'];
			}
		}

		foreach (array_chunk($ids, self::ID_CHUNK) as $chunk)
		{
			DocumentAccessTable::updateMulti($chunk, ['LEVEL' => $targetLevel], true);
		}
	}

	/**
	 * @param array<string, int> $target subject => level
	 * @param int[] $descendantIds
	 */
	private function widen(int $rootId, array $target, array $descendantIds): void
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$now = new DateTime();

		$rows = [];
		foreach ($descendantIds as $nodeId)
		{
			foreach ($target as $subjectCode => $level)
			{
				$rows[] = [
					'DOCUMENT_ID' => (int)$nodeId,
					'SUBJECT_CODE' => (string)$subjectCode,
					'LEVEL' => (int)$level,
					'SOURCE_DOCUMENT_ID' => (int)$rootId,
					// Derived rows are machine-materialised; 0 marks the system as author.
					'CREATED_BY' => 0,
					'CREATED_AT' => $now,
				];

				if (count($rows) >= self::INSERT_CHUNK)
				{
					$this->flushUpsert($connection, $sqlHelper, $rows);
					$rows = [];
				}
			}
		}

		if (!empty($rows))
		{
			$this->flushUpsert($connection, $sqlHelper, $rows);
		}
	}

	/**
	 * Cross-DB batch UPSERT keyed by (DOCUMENT_ID, SUBJECT_CODE, SOURCE_DOCUMENT_ID); only LEVEL is
	 * updated on conflict so an existing recipient's row is never destructively rewritten. SqlHelper
	 * emits MySQL ON DUPLICATE KEY UPDATE / PG ON CONFLICT DO UPDATE from a single call.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function flushUpsert($connection, $sqlHelper, array $rows): void
	{
		if (empty($rows))
		{
			return;
		}

		$sql = $sqlHelper->prepareMergeValues(
			DocumentAccessTable::getTableName(),
			['DOCUMENT_ID', 'SUBJECT_CODE', 'SOURCE_DOCUMENT_ID'],
			$rows,
			['LEVEL'],
		);

		if ($sql !== '')
		{
			$connection->queryExecute($sql);
		}
	}

	/**
	 * @param int[] $ids
	 * @return int[] positive, unique, re-indexed
	 */
	private function normalizeIds(array $ids): array
	{
		return array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
	}
}

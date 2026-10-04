<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Exceptions\DocumentNotFoundException;
use Bitrix\Note\Internal\Exceptions\OrphanRestoreTargetRequiredException;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Bulk\BulkAccessAggregator;
use Bitrix\Note\Internal\Service\Bulk\RestoreOrderer;
use Bitrix\Note\Internal\Service\Bulk\SelectionResolver;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\RecycleBin\RestoreFromRecycleBinService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;

/**
 * [FEAT-kb2-tree-bulk-archive / P3.T2 / API-03] Bulk restore of a selection, for both the
 * archive and recycle sections. Owner of the API-03 contract.
 *
 * Only the selected nodes are restored (withNested=false): no ancestor chain is rebuilt. A
 * selected node whose selected parent is restored first re-attaches under it; a node whose
 * parent is not restored (not selected, still archived/trashed, or gone) falls to the
 * collection root — exactly single-restore placement. The single guarantee bulk adds is the
 * top-down apply order (RestoreOrderer via orderAllowedIds), without which a jointly selected
 * subtree would scatter to the root.
 *
 * Placement is delegated to the canonical single-restore paths:
 *  - archive  -> RestoreDocumentCommand per document (MANAGE-gated upstream by BulkAccessAggregator)
 *  - recycle  -> RestoreFromRecycleBinService per record (canRestore-gated); an orphan whose
 *                original collection is gone and no targetCollectionId was given is reported as
 *                skippedOrphanCount and never rolls the operation back (partial success).
 */
class BulkRestoreDocumentsCommand extends AbstractBulkCommand
{
	private readonly DocumentRepository $repository;
	private readonly RecycleBinRepository $recycleBinRepository;
	private readonly RestoreFromRecycleBinService $restoreService;
	private readonly SearchIndexService $searchIndexService;
	private readonly RestoreOrderer $restoreOrderer;
	private readonly EventLogService $eventLogService;

	/**
	 * Recycle-path restores that came back LIVE, grouped by owning collection — filled while the
	 * chunks are applied, because only the restore result tells whether a record had been archived
	 * before it was trashed (such a document stays archived and must not reach RAG).
	 *
	 * @var array<int, int[]> collectionId => documentIds
	 */
	private array $restoredLiveIdsByCollection = [];

	/**
	 * @param int[] $documentIds selected document ids (recycle callers translate recycleBinIds first)
	 * @param string $section SelectionResolver::SECTION_ARCHIVE or SECTION_RECYCLE
	 * @param int|null $targetCollectionId destination for recycle orphans whose original collection is gone
	 */
	public function __construct(
		int $userId,
		array $documentIds,
		string $section = SelectionResolver::SECTION_ARCHIVE,
		private readonly ?int $targetCollectionId = null,
		?DocumentRepository $repository = null,
		?RecycleBinRepository $recycleBinRepository = null,
		?RestoreFromRecycleBinService $restoreService = null,
		?SearchIndexService $searchIndexService = null,
		?RestoreOrderer $restoreOrderer = null,
		?SelectionResolver $selectionResolver = null,
		?BulkAccessAggregator $accessAggregator = null,
		?EventLogService $eventLogService = null,
	)
	{
		parent::__construct(
			$userId,
			$documentIds,
			$section,
			false, // restore only the selected nodes; never expand subtrees
			$selectionResolver ?? new SelectionResolver(),
			$accessAggregator ?? new BulkAccessAggregator(),
		);
		$this->repository = $repository ?? new DocumentRepository();
		$this->recycleBinRepository = $recycleBinRepository ?? new RecycleBinRepository();
		$this->restoreService = $restoreService ?? new RestoreFromRecycleBinService();
		$this->searchIndexService = $searchIndexService ?? new SearchIndexService();
		$this->restoreOrderer = $restoreOrderer ?? new RestoreOrderer();
		$this->eventLogService = $eventLogService ?? new EventLogService();
	}

	protected function requiredAccessLevel(): int
	{
		return CollectionAccessService::LEVEL_MANAGE;
	}

	protected function recycleCapability(): string
	{
		return BulkAccessAggregator::RECYCLE_CAP_RESTORE;
	}

	/**
	 * Order the whole allowed set ancestors-first so a jointly selected parent is applied
	 * before its descendant (see class doc). Depth is computed within the selection only.
	 *
	 * @param int[] $allowedIds
	 * @return int[]
	 */
	protected function orderAllowedIds(array $allowedIds): array
	{
		if (count($allowedIds) < 2)
		{
			return $allowedIds;
		}

		$metaById = [];
		foreach ($this->repository->getMetaByIds($allowedIds) as $document)
		{
			$parentId = $document->getParentId();
			$metaById[(int)$document->getId()] = [
				'parentId' => $parentId !== null ? (int)$parentId : null,
			];
		}

		return $this->restoreOrderer->order($allowedIds, $metaById);
	}

	protected function applyChunk(array $documentIds): array
	{
		if ($this->section === SelectionResolver::SECTION_RECYCLE)
		{
			return $this->applyRecycleChunk($documentIds);
		}

		return $this->applyArchiveChunk($documentIds);
	}

	/**
	 * @param int[] $documentIds
	 * @return array{processedIds: int[], skippedTransientCount: int}
	 */
	private function applyArchiveChunk(array $documentIds): array
	{
		$processedIds = [];
		$skippedTransient = 0;

		foreach ($documentIds as $documentId)
		{
			$documentId = (int)$documentId;
			try
			{
				// RestoreDocumentCommand owns single-restore placement, position, event and
				// per-document side effects (search index + realtime push, collection un-archive).
				(new RestoreDocumentCommand($documentId, $this->userId))->run();
				$processedIds[] = $documentId;
			}
			catch (DocumentNotFoundException)
			{
				// Raced out of the archive (e.g. already restored) — skip, don't fail the chunk.
				$skippedTransient++;
			}
		}

		return ['processedIds' => $processedIds, 'skippedTransientCount' => $skippedTransient];
	}

	/**
	 * @param int[] $documentIds
	 * @return array{processedIds: int[], skippedOrphanCount: int, skippedTransientCount: int}
	 */
	private function applyRecycleChunk(array $documentIds): array
	{
		$recordsByDocumentId = $this->recycleBinRepository->getByDocumentIds($documentIds);

		$processedIds = [];
		$skippedOrphan = 0;
		$skippedTransient = 0;

		foreach ($documentIds as $documentId)
		{
			$documentId = (int)$documentId;
			$record = $recordsByDocumentId[$documentId] ?? null;
			if ($record === null)
			{
				$skippedTransient++;

				continue;
			}

			$connection = Application::getConnection();
			$connection->startTransaction();
			try
			{
				$result = $this->restoreService->restore($record, $this->targetCollectionId, $this->userId);

				if (!$result->isSuccess())
				{
					// restore() only fails (document missing) before any write — nothing to undo.
					// Commit the empty transaction: nested rollback is unsupported when this runs
					// inside an already-open transaction (tests), nested commit is always safe.
					$connection->commitTransaction();
					$skippedTransient++;

					continue;
				}

				$connection->commitTransaction();
				$processedIds[] = $documentId;

				$data = $result->getData();
				if (($data['wasArchivedBeforeTrash'] ?? false) !== true)
				{
					$collectionId = (int)($data['collectionId'] ?? 0);
					if ($collectionId > 0)
					{
						$this->restoredLiveIdsByCollection[$collectionId][] = $documentId;
					}
				}
			}
			catch (OrphanRestoreTargetRequiredException)
			{
				// Original collection gone and no target supplied: report, do not roll back the run.
				$connection->commitTransaction();
				$skippedOrphan++;
			}
			catch (\Throwable $e)
			{
				$connection->rollbackTransaction();

				throw $e;
			}
		}

		return [
			'processedIds' => $processedIds,
			'skippedOrphanCount' => $skippedOrphan,
			'skippedTransientCount' => $skippedTransient,
		];
	}

	protected function runBatchSideEffects(array $processedDocumentIds): void
	{
		// Archive restore already reindexed each document inside RestoreDocumentCommand; the
		// recycle path restores through the service without indexing, so reindex it here in one
		// batch. Reindex is idempotent, so this is safe for the recycle section only.
		if ($this->section === SelectionResolver::SECTION_RECYCLE)
		{
			// Recycle path restores through the service (no per-document event). Archive path is NOT
			// handled here: applyArchiveChunk delegates to RestoreDocumentCommand, which writes
			// 'archive_restored' itself — recording again would double the event.
			$this->eventLogService->recordMany(EventTable::SCOPE_DOCUMENT, $processedDocumentIds, 'trash_restored', $this->userId);

			$this->searchIndexService->reindexByIds($processedDocumentIds);
		}
	}

	/**
	 * EVENT-NOTE-01 `restored` for the recycle path only, and only for the documents that came back
	 * live: the archive path restores through RestoreDocumentCommand, which emits the event itself
	 * (a second one here would duplicate it), and a document archived before being trashed stays
	 * archived — RAG must not index it.
	 *
	 * @param int[] $processedDocumentIds
	 */
	protected function emitLifecycleForProcessed(array $processedDocumentIds): void
	{
		$restoredLiveIds = [];
		foreach ($this->restoredLiveIdsByCollection as $collectionId => $ids)
		{
			$this->eventPublisher->emitLifecycle(
				OnDocumentLifecycleEvent::RESTORED,
				$collectionId,
				$ids,
			);
			$restoredLiveIds = [...$restoredLiveIds, ...$ids];
		}

		// [D1] refresh the backlinks of the documents these restored sources point at.
		$this->backlinkNotifier->sourcesChanged($restoredLiveIds);
	}
}

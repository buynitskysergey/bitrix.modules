<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Entity\RecycleBin\RecycleBinRecord;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Bulk\BulkAccessAggregator;
use Bitrix\Note\Internal\Service\Bulk\SelectionResolver;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Document\ReparentService;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;

/**
 * [FEAT-kb2-tree-bulk-archive / P2.T4 / API-02] Bulk soft-delete (move to recycle bin) of a
 * tree selection from the active or archive section. Requires MANAGE on each document's
 * owning collection — undeletable documents are reported as skippedByAccessCount, not errors.
 *
 * withNested defaults to false (mirrors single delete after P2.T2): only the selected nodes
 * are trashed and each node's direct children are re-hung onto its parent first. With
 * withNested=true the resolver already expanded the full subtree, so the whole set is trashed.
 */
class BulkDeleteDocumentsCommand extends AbstractBulkCommand
{
	private readonly DocumentRepository $repository;
	private readonly RecycleBinRepository $recycleBinRepository;
	private readonly ReparentService $reparentService;
	private readonly SearchIndexService $searchIndexService;
	private readonly PushNotificationService $pushService;
	private readonly EventLogService $eventLogService;

	/**
	 * @param int[] $documentIds selected root ids
	 * @param string $section SelectionResolver::SECTION_ACTIVE or SECTION_ARCHIVE
	 */
	public function __construct(
		int $userId,
		array $documentIds,
		string $section = SelectionResolver::SECTION_ACTIVE,
		bool $withNested = false,
		?DocumentRepository $repository = null,
		?RecycleBinRepository $recycleBinRepository = null,
		?ReparentService $reparentService = null,
		?SearchIndexService $searchIndexService = null,
		?PushNotificationService $pushService = null,
		?SelectionResolver $selectionResolver = null,
		?BulkAccessAggregator $accessAggregator = null,
		?EventLogService $eventLogService = null,
	)
	{
		parent::__construct(
			$userId,
			$documentIds,
			$section,
			$withNested,
			$selectionResolver ?? new SelectionResolver(),
			$accessAggregator ?? new BulkAccessAggregator(),
		);
		$this->repository = $repository ?? new DocumentRepository();
		$this->recycleBinRepository = $recycleBinRepository ?? new RecycleBinRepository();
		$this->reparentService = $reparentService ?? new ReparentService();
		$this->searchIndexService = $searchIndexService ?? new SearchIndexService();
		$this->pushService = $pushService ?? new PushNotificationService();
		$this->eventLogService = $eventLogService ?? new EventLogService();
	}

	protected function requiredAccessLevel(): int
	{
		return CollectionAccessService::LEVEL_MANAGE;
	}

	protected function lifecycleReason(): ?string
	{
		return OnDocumentLifecycleEvent::DELETED;
	}

	protected function applyChunk(array $documentIds): array
	{
		// No-cascade: re-hang each node's children onto its parent before trashing the node.
		// reparent opens its own reorder transaction, so it runs before the chunk transaction.
		if (!$this->withNested)
		{
			$this->reparentChunkChildren($documentIds);
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$records = [];
			foreach ($documentIds as $id)
			{
				$records[] = RecycleBinRecord::createForUserDelete((int)$id, $this->userId);
			}
			// addBatch is idempotent (INSERT IGNORE / ON CONFLICT DO NOTHING) — an already
			// trashed document is skipped without duplicating its recycle-bin row.
			$this->recycleBinRepository->addBatch($records);

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			throw $e;
		}

		return ['processedIds' => $documentIds];
	}

	protected function runBatchSideEffects(array $processedDocumentIds): void
	{
		// History parity with DeleteDocumentCommand, which records the event on the operation's
		// ROOT only, not per subtree descendant. With withNested=true $processedDocumentIds is the
		// whole expanded subtree, so record only the processed selection roots. No live-push.
		$rootsProcessed = array_values(array_intersect($processedDocumentIds, $this->rootIds));
		$this->eventLogService->recordMany(EventTable::SCOPE_DOCUMENT, $rootsProcessed, 'trashed', $this->userId);

		$this->searchIndexService->deindexDocuments($processedDocumentIds);

		foreach ($this->groupByCollection($processedDocumentIds) as $collectionId => $ids)
		{
			$this->pushService->emitDocumentCascade(
				collectionId: $collectionId,
				documentIds: $ids,
				collectionCommand: 'documentDelete',
				initiatorUserId: $this->userId,
			);
		}
	}

	/**
	 * @param int[] $documentIds
	 */
	private function reparentChunkChildren(array $documentIds): void
	{
		$collectionIds = $this->repository->getCollectionIds($documentIds);
		foreach ($documentIds as $id)
		{
			$collectionId = (int)($collectionIds[(int)$id] ?? 0);
			if ($collectionId > 0)
			{
				$this->reparentService->reparentChildren((int)$id, $collectionId, $this->userId);
			}
		}
	}

	/**
	 * @param int[] $documentIds
	 * @return array<int, int[]> collectionId => documentIds
	 */
	private function groupByCollection(array $documentIds): array
	{
		$byCollection = [];
		foreach ($this->repository->getCollectionIds($documentIds) as $documentId => $collectionId)
		{
			if ($collectionId > 0)
			{
				$byCollection[$collectionId][] = (int)$documentId;
			}
		}

		return $byCollection;
	}
}

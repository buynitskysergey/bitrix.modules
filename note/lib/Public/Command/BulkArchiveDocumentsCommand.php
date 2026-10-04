<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Bulk\BulkAccessAggregator;
use Bitrix\Note\Internal\Service\Bulk\SelectionResolver;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Document\ReparentService;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;

/**
 * [FEAT-kb2-tree-bulk-archive / P2.T3 / API-01] Bulk archive of a tree selection in the
 * active section. Requires MANAGE on each document's owning collection — documents the user
 * cannot manage are reported as skippedByAccessCount, never as an error.
 *
 * withNested defaults to true (whole subtree). With withNested=false only the selected nodes
 * archive and each node's direct children are re-hung onto its parent first (ReparentService).
 */
class BulkArchiveDocumentsCommand extends AbstractBulkCommand
{
	private readonly DocumentRepository $repository;
	private readonly ReparentService $reparentService;
	private readonly SearchIndexService $searchIndexService;
	private readonly PushNotificationService $pushService;
	private readonly EventLogService $eventLogService;

	/**
	 * @param int[] $documentIds selected root ids
	 */
	public function __construct(
		int $userId,
		array $documentIds,
		bool $withNested = true,
		?DocumentRepository $repository = null,
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
			SelectionResolver::SECTION_ACTIVE,
			$withNested,
			$selectionResolver ?? new SelectionResolver(),
			$accessAggregator ?? new BulkAccessAggregator(),
		);
		$this->repository = $repository ?? new DocumentRepository();
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
		return OnDocumentLifecycleEvent::ARCHIVED;
	}

	protected function applyChunk(array $documentIds): array
	{
		// No-cascade: re-hang each node's children onto its parent before archiving the node.
		// reparent runs its own reorder transaction, so it happens outside the atomic UPDATE.
		if (!$this->withNested)
		{
			$this->reparentChunkChildren($documentIds);
		}

		// archiveByIds is a single atomic UPDATE guarding IS_ARCHIVED='N'; no wrapping tx needed.
		$this->repository->archiveByIds($documentIds, $this->userId);

		return ['processedIds' => $documentIds];
	}

	protected function runBatchSideEffects(array $processedDocumentIds): void
	{
		// History parity with ArchiveDocumentCommand, which records the event on the operation's
		// ROOT only, not per subtree descendant. With withNested=true $processedDocumentIds is the
		// whole expanded subtree, so record only the processed selection roots. No live-push.
		$rootsProcessed = array_values(array_intersect($processedDocumentIds, $this->rootIds));
		$this->eventLogService->recordMany(EventTable::SCOPE_DOCUMENT, $rootsProcessed, 'archived', $this->userId);

		$this->searchIndexService->deindexDocuments($processedDocumentIds);

		foreach ($this->groupByCollection($processedDocumentIds) as $collectionId => $ids)
		{
			$this->pushService->emitDocumentCascade(
				collectionId: $collectionId,
				documentIds: $ids,
				collectionCommand: 'documentArchive',
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

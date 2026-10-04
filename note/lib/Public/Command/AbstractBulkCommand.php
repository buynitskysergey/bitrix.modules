<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Bulk\BulkAccessAggregator;
use Bitrix\Note\Internal\Service\Bulk\BulkOutcome;
use Bitrix\Note\Internal\Service\Bulk\SelectionResolver;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Service\Link\BacklinkLifecycleNotifier;

/**
 * [FEAT-kb2-tree-bulk-archive / P1.T4] Template-method skeleton wiring the bulk pipeline:
 * SelectionResolver -> BulkAccessAggregator -> chunked apply -> BulkOutcome.
 *
 * Concrete actions (archive/delete/restore/hardDelete/move) are heirs — none exist in
 * Phase 1. Heirs own the chunk mutation (applyChunk) and its transaction boundary; the
 * skeleton keeps best-effort side effects (reindex/push) outside any transaction so a
 * side-effect failure never rolls back an applied chunk.
 */
abstract class AbstractBulkCommand extends AbstractCommand
{
	protected const CHUNK_SIZE = 200;

	// Guides heirs' push strategy: above this many affected documents, degrade per-document
	// realtime pushes to a single collection-level requestRefetch. No pushes are sent in Phase 1.
	protected const REALTIME_BATCH_THRESHOLD = PushNotificationService::REALTIME_BATCH_THRESHOLD;

	/**
	 * @param int[] $rootIds
	 * @param string $section one of SelectionResolver::SECTION_*
	 */
	public function __construct(
		protected readonly int $userId,
		protected readonly array $rootIds,
		protected readonly string $section,
		protected readonly bool $withNested,
		protected readonly SelectionResolver $selectionResolver = new SelectionResolver(),
		protected readonly BulkAccessAggregator $accessAggregator = new BulkAccessAggregator(),
		// EVENT-NOTE-01 emission for the whole bulk pipeline — see emitLifecycleForProcessed().
		protected readonly DomainEventPublisher $eventPublisher = new DomainEventPublisher(),
		protected readonly DocumentRepository $eventDocumentRepository = new DocumentRepository(),
		// [D1] Refreshes the backlinks of the targets of the processed sources — see emitLifecycleForProcessed().
		protected readonly BacklinkLifecycleNotifier $backlinkNotifier = new BacklinkLifecycleNotifier(),
	) {}

	protected function execute(): Result
	{
		$result = new Result();

		$resolution = $this->selectionResolver->resolve($this->rootIds, $this->section, $this->withNested);
		if ($resolution['limitExceeded'] === true)
		{
			$result->setData(['outcome' => BulkOutcome::limitExceeded()->toArray()]);

			return $result;
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($this->userId);
		$access = $this->accessAggregator->aggregate(
			$resolution['ids'],
			$resolution['byCollection'],
			$this->section,
			$this->requiredAccessLevel(),
			$this->userId,
			$accessCodes,
			$this->recycleCapability(),
		);

		$outcome = BulkOutcome::fromAccess(count($access['skippedByAccessIds']));
		$processedIds = [];

		// Ordering seam: identity by default, so archive/delete heirs are unchanged. The
		// restore heir overrides it to sort the whole allowed set ancestors-first BEFORE
		// chunking — a parent then always lands in an equal or earlier chunk than its child.
		$allowedIds = $this->orderAllowedIds($access['allowedIds']);

		foreach (array_chunk($allowedIds, self::CHUNK_SIZE) as $chunk)
		{
			$chunkResult = $this->applyChunk($chunk);
			$processedIds = [...$processedIds, ...$chunkResult['processedIds']];
			$outcome = $outcome->withChunk(
				count($chunkResult['processedIds']),
				$chunkResult['skippedOrphanCount'] ?? 0,
				$chunkResult['skippedTransientCount'] ?? 0,
			);
		}

		// Best-effort, outside any transaction: an index/push failure must not undo applied chunks.
		if (!empty($processedIds))
		{
			try
			{
				$this->runBatchSideEffects($processedIds);
			}
			catch (\Throwable)
			{
			}

			// EVENT-NOTE-01 for the applied selection. Kept in its OWN best-effort block: a failing
			// side effect above must not swallow the event, and a failure while assembling the event
			// must not fail a bulk operation that is already applied (the subscriber is idempotent, so
			// a lost event is repaired by the next reconcile — a false error to the caller is not).
			try
			{
				$this->emitLifecycleForProcessed($processedIds);
			}
			catch (\Throwable)
			{
			}
		}

		$result->setData(['outcome' => $outcome->toArray()]);

		return $result;
	}

	/**
	 * Applies one chunk of allowed document ids and reports its counts. The heir owns the
	 * transaction boundary appropriate to the action type.
	 *
	 * @param int[] $documentIds
	 * @return array{processedIds: int[], skippedOrphanCount?: int, skippedTransientCount?: int}
	 */
	abstract protected function applyChunk(array $documentIds): array;

	/**
	 * Orders the allowed set before it is chunked and applied. Identity by default; heirs
	 * that need a global apply order (bulk restore) override it. Because chunking preserves
	 * order, a global sort here fixes the intra- and inter-chunk order in one place.
	 *
	 * @param int[] $allowedIds
	 * @return int[]
	 */
	protected function orderAllowedIds(array $allowedIds): array
	{
		return $allowedIds;
	}

	/**
	 * Minimum owning-collection level the action requires (ignored for the recycle section,
	 * where BulkAccessAggregator branches on recycleCapability instead).
	 */
	protected function requiredAccessLevel(): int
	{
		return CollectionAccessService::LEVEL_MANAGE;
	}

	/**
	 * Which recycle-bin capability gates the action; relevant only for the recycle section.
	 */
	protected function recycleCapability(): string
	{
		return BulkAccessAggregator::RECYCLE_CAP_HARD_DELETE;
	}

	/**
	 * Batch side effects (search reindex, realtime push) for the successfully processed
	 * documents. No-op by default; heirs override. Always invoked outside the transaction.
	 *
	 * @param int[] $processedDocumentIds
	 */
	protected function runBatchSideEffects(array $processedDocumentIds): void
	{
	}

	/**
	 * The EVENT-NOTE-01 reason this action publishes, or null when it publishes nothing here —
	 * which is the right answer for an heir that delegates the mutation to a single-document
	 * command that emits on its own (emitting again would duplicate the event). An heir mutating
	 * documents directly must name its reason, otherwise consumers (RAG source sync) never learn
	 * about the bulk operation and stay stale until the next reconcile.
	 */
	protected function lifecycleReason(): ?string
	{
		return null;
	}

	/**
	 * Publishes the lifecycle event for the applied ids: one event per owning collection, since the
	 * event carries a single collectionId. hardDeleted is the exception — its rows are already gone,
	 * so the collection cannot be resolved and the contract allows a null collectionId there.
	 *
	 * Heirs override when only a subset of the processed ids is publishable (see BulkRestore).
	 *
	 * @param int[] $processedDocumentIds
	 */
	protected function emitLifecycleForProcessed(array $processedDocumentIds): void
	{
		$reason = $this->lifecycleReason();
		if ($reason === null)
		{
			return;
		}

		if ($reason === OnDocumentLifecycleEvent::HARD_DELETED)
		{
			$this->eventPublisher->emitLifecycle($reason, null, $processedDocumentIds);

			return;
		}

		foreach ($this->groupByOwningCollection($processedDocumentIds) as $collectionId => $ids)
		{
			$this->eventPublisher->emitLifecycle($reason, $collectionId, $ids);
		}

		// [D1] archive/trash change what a backlink popover reads off these sources; refresh their
		// targets. Hard delete returns above — HardDeleteService already notified its targets.
		$this->backlinkNotifier->sourcesChanged($processedDocumentIds);
	}

	/**
	 * @param int[] $documentIds
	 * @return array<int, int[]> collectionId => documentIds
	 */
	protected function groupByOwningCollection(array $documentIds): array
	{
		$byCollection = [];
		foreach ($this->eventDocumentRepository->getCollectionIds($documentIds) as $documentId => $collectionId)
		{
			$collectionId = (int)$collectionId;
			if ($collectionId > 0)
			{
				$byCollection[$collectionId][] = (int)$documentId;
			}
		}

		return $byCollection;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Entity\RecycleBin\RecycleBinRecord;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Bulk\BulkOutcome;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Service\Link\BacklinkLifecycleNotifier;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;

/**
 * [FEAT-kb2-tree-bulk-archive / P2.T5 / API-07] "Select all": soft-delete (move to recycle
 * bin) every live document of a collection. The affected set is computed server-side over the
 * whole collection structure (getLiveCollectionDocumentIds), not from loaded rows. The whole
 * subtree goes, so no re-hang is needed. The collection entity itself is never touched.
 *
 * Access (MANAGE on the collection) is asserted by the caller/endpoint; the whole collection
 * is under one owner, so nothing is skipped for access and processedCount == deleted count.
 */
class DeleteAllInCollectionCommand extends AbstractCommand
{
	private readonly int $collectionId;
	private readonly int $userId;
	private readonly DocumentRepository $repository;
	private readonly RecycleBinRepository $recycleBinRepository;
	private readonly SearchIndexService $searchIndexService;
	private readonly PushNotificationService $pushService;
	private readonly EventLogService $eventLogService;
	private readonly DomainEventPublisher $eventPublisher;
	private readonly BacklinkLifecycleNotifier $backlinkNotifier;

	public function __construct(
		int $collectionId,
		int $userId,
		?DocumentRepository $repository = null,
		?RecycleBinRepository $recycleBinRepository = null,
		?SearchIndexService $searchIndexService = null,
		?PushNotificationService $pushService = null,
		?EventLogService $eventLogService = null,
		?DomainEventPublisher $eventPublisher = null,
		?BacklinkLifecycleNotifier $backlinkNotifier = null,
	)
	{
		$this->collectionId = $collectionId;
		$this->userId = $userId;
		$this->repository = $repository ?? new DocumentRepository();
		$this->recycleBinRepository = $recycleBinRepository ?? new RecycleBinRepository();
		$this->searchIndexService = $searchIndexService ?? new SearchIndexService();
		$this->pushService = $pushService ?? new PushNotificationService();
		$this->eventLogService = $eventLogService ?? new EventLogService();
		$this->eventPublisher = $eventPublisher ?? new DomainEventPublisher();
		$this->backlinkNotifier = $backlinkNotifier ?? new BacklinkLifecycleNotifier();
	}

	protected function execute(): Result
	{
		$result = new Result();

		$documentIds = $this->repository->getLiveCollectionDocumentIds($this->collectionId);
		if (empty($documentIds))
		{
			$result->setData(['outcome' => (new BulkOutcome())->toArray()]);

			return $result;
		}

		// Capture top-level roots BEFORE addBatch moves the documents into the recycle bin (after
		// which the live-root query, which excludes trashed documents, would return nothing).
		$rootIds = $this->repository->getLiveCollectionRootIds($this->collectionId);

		$records = [];
		foreach ($documentIds as $documentId)
		{
			$records[] = RecycleBinRecord::createForUserDelete((int)$documentId, $this->userId);
		}
		// addBatch chunks INSERT IGNORE / ON CONFLICT in 500-row batches; idempotent, so an
		// already-trashed document never gets a duplicate recycle-bin row.
		$this->recycleBinRepository->addBatch($records);

		// History parity with DeleteDocumentCommand (root-only event): the whole collection is
		// trashed, but the event is recorded only on top-level roots to avoid spamming the feed of
		// every descendant. No per-event live-push (see recordMany).
		$this->eventLogService->recordMany(EventTable::SCOPE_DOCUMENT, $rootIds, 'trashed', $this->userId);

		try
		{
			$this->searchIndexService->deindexDocuments($documentIds);
		}
		catch (\Throwable)
		{
		}

		$this->pushService->emitDocumentCascade(
			collectionId: $this->collectionId,
			documentIds: $documentIds,
			collectionCommand: 'documentDelete',
			initiatorUserId: $this->userId,
		);

		// EVENT-NOTE-01: the whole collection in one event — every id shares this collection.
		// Best-effort: the operation is already applied, a failure here must not turn it into an error.
		try
		{
			$this->eventPublisher->emitLifecycle(
				OnDocumentLifecycleEvent::DELETED,
				$this->collectionId,
				$documentIds,
			);
		}
		catch (\Throwable)
		{
		}

		// [D1] trashing the collection's documents changes their visibility; refresh their targets.
		$this->backlinkNotifier->sourcesChanged($documentIds);

		$result->setData([
			'outcome' => (new BulkOutcome(processedCount: count($documentIds)))->toArray(),
		]);

		return $result;
	}
}

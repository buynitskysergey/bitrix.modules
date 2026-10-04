<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Bulk\BulkOutcome;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Service\Link\BacklinkLifecycleNotifier;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;

/**
 * [FEAT-kb2-tree-bulk-archive / P2.T5 / API-06] "Select all": archive every live document of
 * a collection. The affected set is computed server-side over the whole collection structure
 * (getLiveCollectionDocumentIds), not from the rows the client happened to load. The
 * collection entity itself is never touched — only its documents.
 *
 * Access (MANAGE on the collection) is asserted by the caller/endpoint; the whole collection
 * is under one owner, so nothing is skipped for access and processedCount == archived count.
 */
class ArchiveAllInCollectionCommand extends AbstractCommand
{
	private readonly int $collectionId;
	private readonly int $userId;
	private readonly DocumentRepository $repository;
	private readonly SearchIndexService $searchIndexService;
	private readonly PushNotificationService $pushService;
	private readonly EventLogService $eventLogService;
	private readonly DomainEventPublisher $eventPublisher;
	private readonly BacklinkLifecycleNotifier $backlinkNotifier;

	public function __construct(
		int $collectionId,
		int $userId,
		?DocumentRepository $repository = null,
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

		// Capture top-level roots BEFORE the archive UPDATE flips IS_ARCHIVED to 'Y' (which would
		// make the live-root query return nothing).
		$rootIds = $this->repository->getLiveCollectionRootIds($this->collectionId);

		// Single atomic UPDATE over the whole collection (guards IS_ARCHIVED='N').
		$this->repository->archiveByIds($documentIds, $this->userId);

		// History parity with ArchiveDocumentCommand (root-only event): the whole collection is
		// archived, but the event is recorded only on top-level roots to avoid spamming the feed
		// of every descendant. No per-event live-push (see recordMany).
		$this->eventLogService->recordMany(EventTable::SCOPE_DOCUMENT, $rootIds, 'archived', $this->userId);

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
			collectionCommand: 'documentArchive',
			initiatorUserId: $this->userId,
		);

		// EVENT-NOTE-01: the whole collection in one event — every id shares this collection.
		// Best-effort: the operation is already applied, a failure here must not turn it into an error.
		try
		{
			$this->eventPublisher->emitLifecycle(
				OnDocumentLifecycleEvent::ARCHIVED,
				$this->collectionId,
				$documentIds,
			);
		}
		catch (\Throwable)
		{
		}

		// [D1] archiving the collection's documents changes their visibility; refresh their targets.
		$this->backlinkNotifier->sourcesChanged($documentIds);

		$result->setData([
			'outcome' => (new BulkOutcome(processedCount: count($documentIds)))->toArray(),
		]);

		return $result;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Exceptions\DocumentNotFoundException;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Service\Link\BacklinkLifecycleNotifier;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Collection\CollectionRestoreService;
use Bitrix\Note\Internal\Service\Document\Position\PositionCalculator;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;

class RestoreDocumentCommand extends AbstractCommand
{
	private readonly int $id;
	private readonly int $userId;
	private readonly DocumentRepository $repository;
	private readonly SearchIndexService $searchIndexService;
	private readonly PositionCalculator $positionCalculator;
	private readonly CollectionRepository $collectionRepository;
	private readonly CollectionRestoreService $collectionRestoreService;
	private readonly PushNotificationService $pushService;
	private readonly DomainEventPublisher $eventPublisher;
	private readonly EventLogService $eventLogService;
	private readonly BacklinkLifecycleNotifier $backlinkNotifier;

	public function __construct(
		int $id,
		int $userId,
		?DocumentRepository $repository = null,
		?SearchIndexService $searchIndexService = null,
		?PositionCalculator $positionCalculator = null,
		?CollectionRepository $collectionRepository = null,
		?PushNotificationService $pushService = null,
		?CollectionRestoreService $collectionRestoreService = null,
		?DomainEventPublisher $eventPublisher = null,
		?EventLogService $eventLogService = null,
		?BacklinkLifecycleNotifier $backlinkNotifier = null,
	)
	{
		$this->id = $id;
		$this->userId = $userId;
		$this->repository = $repository ?? new DocumentRepository();
		$this->searchIndexService = $searchIndexService ?? new SearchIndexService();
		$this->positionCalculator = $positionCalculator ?? new PositionCalculator();
		$this->collectionRepository = $collectionRepository ?? new CollectionRepository();
		$this->pushService = $pushService ?? new PushNotificationService();
		$this->collectionRestoreService = $collectionRestoreService
			?? new CollectionRestoreService($this->collectionRepository, $this->pushService);
		$this->eventPublisher = $eventPublisher ?? new DomainEventPublisher();
		$this->eventLogService = $eventLogService ?? new EventLogService();
		$this->backlinkNotifier = $backlinkNotifier ?? new BacklinkLifecycleNotifier();
	}

	protected function execute(): Result
	{
		$document = $this->repository->getById($this->id);
		if ($document === null || !$document->getIsArchived())
		{
			throw new DocumentNotFoundException();
		}

		$collectionId = (int)$document->getCollectionId();
		$restoredCollection = null;
		if ($collectionId > 0)
		{
			$restoreResult = $this->collectionRestoreService->restore($collectionId, $this->userId);
			if (($restoreResult->getData()['transitioned'] ?? false) === true)
			{
				$restoredCollection = $restoreResult->getData()['collection'] ?? null;
			}
		}

		$originalParentId = $document->getParentId() !== null ? (int)$document->getParentId() : null;

		$targetParent = null;
		if ($originalParentId !== null)
		{
			$parent = $this->repository->getById($originalParentId);
			if ($parent !== null && !$parent->getIsArchived())
			{
				$targetParent = (int)$parent->getId();
			}
		}

		$position = $this->positionCalculator->calculateNextPosition(
			$this->repository->getMaxPosition($collectionId, $targetParent)
		);

		// DocumentTable::update() is a single atomic write; no wrapping transaction is
		// needed — one would only introduce a nested-rollback hazard when this command
		// runs inside an already-open transaction (e.g. tests).
		DocumentTable::update($this->id, [
			'IS_ARCHIVED' => 'N',
			'ARCHIVED_AT' => null,
			'ARCHIVED_BY' => null,
			'PARENT_ID' => $targetParent,
			'POSITION' => $position,
			'UPDATED_AT' => new DateTime(),
			'UPDATED_BY' => $this->userId,
		]);

		// Best-effort: a failure to log the event must not undo an already-restored document.
		if (Configuration::isActivityEnabled())
		{
			try
			{
				$this->eventLogService->record(EventTable::SCOPE_DOCUMENT, $this->id, 'archive_restored', $this->userId);
			}
			catch (\Throwable)
			{
			}
		}

		try
		{
			$this->searchIndexService->indexDocument($this->id);
		}
		catch (\Throwable)
		{
		}

		DocumentTable::cleanCache();
		$restored = $this->repository->getById($this->id);

		$this->emitDocumentRestore($restored, $collectionId, $targetParent, $position);
		$this->eventPublisher->emitLifecycle(
			OnDocumentLifecycleEvent::RESTORED,
			$collectionId,
			[$this->id],
		);
		// [D1] restoring brings this source back into view; refresh the backlinks of what it points at.
		$this->backlinkNotifier->sourcesChanged([$this->id]);

		$result = new Result();
		$result->setData([
			'id' => $this->id,
			'collectionId' => $collectionId,
			'parentId' => $targetParent,
			'position' => $position,
			'document' => $restored,
			'restoredCollection' => $restoredCollection,
		]);

		return $result;
	}

	private function emitDocumentRestore(
		?Document $restored,
		int $collectionId,
		?int $parentId,
		int $position,
	): void
	{
		$documentId = $this->id;
		$initiatorUserId = $this->userId;
		$pushService = $this->pushService;
		$title = $restored !== null ? (string)$restored->getTitle() : '';
		$hasChildrenMap = $this->repository->getHasChildrenMap($collectionId, [$documentId]);
		$hasChildren = (bool)($hasChildrenMap[$documentId] ?? false);

		$payload = [
			'documentId' => $documentId,
			'collectionId' => $collectionId,
			'parentId' => $parentId,
			'position' => $position,
			'title' => $title,
			'hasChildren' => $hasChildren,
		];

		$pushService->notifyDocumentGrantees(
			$collectionId,
			[$documentId],
			$initiatorUserId,
			'documentRestore',
			$payload,
		);

		$pushService->dispatchAfterCommit(static function () use (
			$pushService, $collectionId, $documentId, $payload, $initiatorUserId,
		): void {
			$pushService->sendToCollection($collectionId, 'documentRestore', $payload, $initiatorUserId);
			$pushService->sendToDocument($documentId, 'documentRestore', $payload, $initiatorUserId);
		});
	}
}

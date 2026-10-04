<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Exceptions\MainDocumentOperationException;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Service\Link\BacklinkLifecycleNotifier;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsDictionary;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsService;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Document\ReparentService;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;

class ArchiveDocumentCommand extends AbstractCommand
{
	private readonly int $id;
	private readonly int $userId;
	private readonly DocumentRepository $repository;
	private readonly SearchIndexService $searchIndexService;
	private readonly PushNotificationService $pushService;
	private readonly EventLogService $eventLogService;
	private readonly ReparentService $reparentService;
	private readonly bool $notifyInitiator;
	private readonly DomainEventPublisher $eventPublisher;
	private readonly BacklinkLifecycleNotifier $backlinkNotifier;
	private readonly bool $withNested;
	private readonly string $analyticsType;
	// Failure is signalled via data['success']=false, so track the real archive outcome here.
	private bool $succeeded = false;

	public function __construct(
		int $id,
		int $userId,
		?DocumentRepository $repository = null,
		?SearchIndexService $searchIndexService = null,
		?PushNotificationService $pushService = null,
		// REST writes are out-of-band: notify the initiator's own sessions instead of skipping them.
		bool $notifyInitiator = false,
		?DomainEventPublisher $eventPublisher = null,
		?EventLogService $eventLogService = null,
		// Archive cascades over the whole subtree by default (matches the "archive this and
		// everything under it" mental model). Opt out (withNested=false) to keep the children:
		// they are re-hung onto the node's parent and only the node itself is archived.
		bool $withNested = true,
		?ReparentService $reparentService = null,
		string $analyticsType = AnalyticsDictionary::TYPE_BK,
		?BacklinkLifecycleNotifier $backlinkNotifier = null,
	)
	{
		$this->analyticsType = $analyticsType;
		$this->id = $id;
		$this->userId = $userId;
		$this->repository = $repository ?? new DocumentRepository();
		$this->searchIndexService = $searchIndexService ?? new SearchIndexService();
		$this->pushService = $pushService ?? new PushNotificationService();
		$this->notifyInitiator = $notifyInitiator;
		$this->eventPublisher = $eventPublisher ?? new DomainEventPublisher();
		$this->eventLogService = $eventLogService ?? new EventLogService();
		$this->withNested = $withNested;
		$this->reparentService = $reparentService ?? new ReparentService();
		$this->backlinkNotifier = $backlinkNotifier ?? new BacklinkLifecycleNotifier();
	}

	protected function execute(): Result
	{
		// The main document carries the collection description; it must never be archived
		// independently - only the owning collection's cascade may remove it.
		if ($this->repository->isMainDocument($this->id))
		{
			throw new MainDocumentOperationException();
		}

		$document = $this->repository->getMetaById($this->id, ['ID', 'COLLECTION_ID', 'IS_ARCHIVED']);
		if ($document === null || $document->getIsArchived())
		{
			return $this->createResult(['success' => false]);
		}

		// Re-hang direct children onto the node's parent BEFORE archiving the node, so they
		// stay active. reorder runs its own transaction, hence outside archiveByIds below.
		if (!$this->withNested)
		{
			$this->reparentService->reparentChildren(
				$this->id,
				(int)$document->getCollectionId(),
				$this->userId,
			);
		}

		$subtreeIds = $this->withNested
			? $this->repository->getSubtreeIds($this->id, (int)$document->getCollectionId())
			: [$this->id]
		;
		if (empty($subtreeIds))
		{
			$subtreeIds = [$this->id];
		}

		// archiveByIds() is a single atomic UPDATE over the whole subtree; no wrapping
		// transaction is needed — one would only introduce a nested-rollback hazard when
		// this command runs inside an already-open transaction (e.g. tests).
		$this->repository->archiveByIds($subtreeIds, $this->userId);

		// Event on the operation's root only ($this->id), not per subtree descendant.
		// Best-effort: a failure to log the event must not undo an already-archived subtree.
		if (Configuration::isActivityEnabled())
		{
			try
			{
				$this->eventLogService->record(EventTable::SCOPE_DOCUMENT, $this->id, 'archived', $this->userId);
			}
			catch (\Throwable)
			{
			}
		}

		try
		{
			$this->searchIndexService->deindexDocuments($subtreeIds);
		}
		catch (\Throwable)
		{
		}

		$this->emitDocumentArchive((int)$document->getCollectionId(), $subtreeIds);
		$this->eventPublisher->emitLifecycle(
			OnDocumentLifecycleEvent::ARCHIVED,
			(int)$document->getCollectionId(),
			$subtreeIds,
		);
		// [D1] archive changes this source's visibility; refresh the backlinks of what it points at.
		$this->backlinkNotifier->sourcesChanged($subtreeIds);

		$this->succeeded = true;

		return $this->createResult(['success' => true, 'archivedIds' => $subtreeIds]);
	}

	protected function afterRun(): void
	{
		AnalyticsService::documentArchived($this->succeeded, $this->analyticsType);
	}

	private function emitDocumentArchive(int $collectionId, array $archivedIds): void
	{
		$this->pushService->emitDocumentCascade(
			collectionId: $collectionId,
			documentIds: $archivedIds,
			collectionCommand: 'documentArchive',
			initiatorUserId: $this->notifyInitiator ? null : $this->userId,
		);
	}

	private function createResult(array $data = []): Result
	{
		$result = new Result();
		if (!empty($data))
		{
			$result->setData($data);
		}

		return $result;
	}
}

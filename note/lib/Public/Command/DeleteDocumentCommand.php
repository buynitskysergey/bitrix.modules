<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Exceptions\MainDocumentOperationException;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Service\Link\BacklinkLifecycleNotifier;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsDictionary;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsService;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Document\ReparentService;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\RecycleBin\MoveToRecycleBinService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;

class DeleteDocumentCommand extends AbstractCommand
{
	private readonly int $id;
	private readonly int $userId;
	private readonly DocumentRepository $repository;
	private readonly RecycleBinRepository $recycleBinRepository;
	private readonly MoveToRecycleBinService $moveService;
	private readonly SearchIndexService $searchIndexService;
	private readonly PushNotificationService $pushService;
	private readonly EventLogService $eventLogService;
	private readonly ReparentService $reparentService;
	private readonly bool $notifyInitiator;
	private readonly DomainEventPublisher $eventPublisher;
	private readonly BacklinkLifecycleNotifier $backlinkNotifier;
	private readonly bool $withNested;
	private readonly string $analyticsType;
	// Failure is signalled via data['success']=false, so track the real delete outcome here.
	private bool $succeeded = false;

	public function __construct(
		int $id,
		int $userId = 0,
		?DocumentRepository $repository = null,
		?RecycleBinRepository $recycleBinRepository = null,
		?MoveToRecycleBinService $moveService = null,
		?SearchIndexService $searchIndexService = null,
		?PushNotificationService $pushService = null,
		// REST writes are out-of-band: notify the initiator's own sessions instead of skipping them.
		bool $notifyInitiator = false,
		?DomainEventPublisher $eventPublisher = null,
		?EventLogService $eventLogService = null,
		// Delete cascades over the whole subtree by default (matches the "delete this and
		// everything under it" mental model). Opt out (withNested=false) to keep the children:
		// they are re-hung onto the node's parent and only the node itself is trashed.
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
		$this->recycleBinRepository = $recycleBinRepository ?? new RecycleBinRepository();
		$this->moveService = $moveService ?? new MoveToRecycleBinService();
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
		// The main document is removed only via the owning collection's cascade delete,
		// never through an independent delete-to-recycle-bin.
		if ($this->repository->isMainDocument($this->id))
		{
			throw new MainDocumentOperationException();
		}

		$document = $this->repository->getMetaById($this->id);
		if ($document === null)
		{
			return $this->createResult(['success' => false]);
		}

		if ($this->recycleBinRepository->isInRecycleBin($this->id))
		{
			return $this->createResult(['success' => false]);
		}

		// Re-hang direct children onto the node's parent BEFORE trashing the node, so they
		// survive the delete. reorder runs its own transaction, hence outside the one below.
		if (!$this->withNested)
		{
			$this->reparentService->reparentChildren(
				$this->id,
				(int)$document->getCollectionId(),
				$this->userId,
			);
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$trashedIds = $this->withNested
				? $this->moveService->moveSubtree($this->id, $this->userId)
				: $this->moveService->moveNode($this->id, $this->userId);

			// Event on the operation's root only ($this->id), not per subtree descendant.
			if (Configuration::isActivityEnabled())
			{
				$this->eventLogService->record(EventTable::SCOPE_DOCUMENT, $this->id, 'trashed', $this->userId);
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			throw $e;
		}

		try
		{
			$this->searchIndexService->deindexDocuments($trashedIds);
		}
		catch (\Throwable)
		{
		}

		$this->emitDocumentDelete((int)$document->getCollectionId(), $trashedIds);
		$this->eventPublisher->emitLifecycle(
			OnDocumentLifecycleEvent::DELETED,
			(int)$document->getCollectionId(),
			$trashedIds,
		);
		// [D1] trashing changes this source's visibility; refresh the backlinks of what it points at.
		$this->backlinkNotifier->sourcesChanged($trashedIds);

		$this->succeeded = true;

		return $this->createResult(['success' => true, 'trashedIds' => $trashedIds]);
	}

	protected function afterRun(): void
	{
		AnalyticsService::documentDeleted($this->succeeded, $this->analyticsType);
	}

	private function emitDocumentDelete(int $collectionId, array $trashedIds): void
	{
		if (empty($trashedIds))
		{
			return;
		}

		$this->pushService->emitDocumentCascade(
			collectionId: $collectionId,
			documentIds: $trashedIds,
			collectionCommand: 'documentDelete',
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

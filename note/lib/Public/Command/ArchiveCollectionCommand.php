<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Service\Link\BacklinkLifecycleNotifier;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsDictionary;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsService;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;

class ArchiveCollectionCommand extends AbstractCommand
{
	private readonly int $collectionId;
	private readonly int $userId;
	private readonly CollectionRepository $collectionRepository;
	private readonly DocumentRepository $documentRepository;
	private readonly SearchIndexService $searchIndexService;
	private readonly PushNotificationService $pushService;
	private readonly DomainEventPublisher $eventPublisher;
	private readonly BacklinkLifecycleNotifier $backlinkNotifier;
	private readonly string $analyticsType;
	// Failure is signalled via data['success']=false, so track the real archive outcome here.
	private bool $succeeded = false;

	public function __construct(
		int $collectionId,
		int $userId,
		?CollectionRepository $collectionRepository = null,
		?DocumentRepository $documentRepository = null,
		?SearchIndexService $searchIndexService = null,
		?PushNotificationService $pushService = null,
		?DomainEventPublisher $eventPublisher = null,
		string $analyticsType = AnalyticsDictionary::TYPE_BK,
		?BacklinkLifecycleNotifier $backlinkNotifier = null,
	)
	{
		$this->analyticsType = $analyticsType;
		$this->collectionId = $collectionId;
		$this->userId = $userId;
		$this->collectionRepository = $collectionRepository ?? new CollectionRepository();
		$this->documentRepository = $documentRepository ?? new DocumentRepository();
		$this->searchIndexService = $searchIndexService ?? new SearchIndexService();
		$this->pushService = $pushService ?? new PushNotificationService();
		$this->eventPublisher = $eventPublisher ?? new DomainEventPublisher();
		$this->backlinkNotifier = $backlinkNotifier ?? new BacklinkLifecycleNotifier();
	}

	protected function execute(): Result
	{
		if ($this->collectionId <= 0)
		{
			return $this->createResult(['success' => false, 'archivedIds' => []]);
		}

		$collection = $this->collectionRepository->getById($this->collectionId);
		if ($collection === null || $collection->getIsArchived())
		{
			return $this->createResult(['success' => false, 'archivedIds' => []]);
		}

		$documentIds = $this->documentRepository->getLiveCollectionDocumentIds($this->collectionId);
		if (!empty($documentIds))
		{
			$this->documentRepository->archiveByIds($documentIds, $this->userId);

			try
			{
				$this->searchIndexService->deindexDocuments($documentIds);
			}
			catch (\Throwable)
			{
			}
		}

		$collectionArchived = $this->collectionRepository->archiveById($this->collectionId);

		if ($collectionArchived)
		{
			$this->emitCollectionArchive($this->collectionId, $documentIds);
			$this->eventPublisher->emitLifecycle(
				OnDocumentLifecycleEvent::ARCHIVED,
				$this->collectionId,
				$documentIds,
			);
			// [D1] archiving the collection changes its documents' visibility; refresh their targets.
			$this->backlinkNotifier->sourcesChanged($documentIds);
		}

		$this->succeeded = $collectionArchived;

		return $this->createResult([
			'success' => $collectionArchived,
			'archivedIds' => $documentIds,
		]);
	}

	protected function afterRun(): void
	{
		AnalyticsService::collectionArchived($this->succeeded, $this->analyticsType);
	}

	private function emitCollectionArchive(int $collectionId, array $archivedDocumentIds): void
	{
		$this->pushService->emitDocumentCascade(
			collectionId: $collectionId,
			documentIds: $archivedDocumentIds,
			collectionCommand: 'collectionArchive',
			globalCommand: 'collectionArchive',
			globalPayload: ['collectionId' => $collectionId],
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

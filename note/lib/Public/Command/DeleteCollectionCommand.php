<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Service\Link\BacklinkLifecycleNotifier;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsDictionary;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsService;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Import\CollectionImportLockService;
use Bitrix\Note\Internal\Service\RecycleBin\HardDeleteService;
use Bitrix\Note\Internal\Service\RecycleBin\MoveToRecycleBinService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;

class DeleteCollectionCommand extends AbstractCommand
{
	private readonly int $id;
	private readonly int $userId;
	private readonly CollectionRepository $collectionRepository;
	private readonly MoveToRecycleBinService $moveService;
	private readonly SearchIndexService $searchIndexService;
	private readonly CollectionImportLockService $importLockService;
	private readonly PushNotificationService $pushService;
	private readonly RecycleBinRepository $recycleBinRepository;
	private readonly DomainEventPublisher $eventPublisher;
	private readonly DocumentRepository $documentRepository;
	private readonly HardDeleteService $hardDeleteService;
	private readonly FavoriteRepository $favoriteRepository;
	private readonly BacklinkLifecycleNotifier $backlinkNotifier;
	private readonly string $analyticsType;
	// Failure is signalled via data['success']=false, so $result->isSuccess() is unreliable here.
	private bool $succeeded = false;

	public function __construct(
		int $id,
		int $userId = 0,
		?CollectionRepository $collectionRepository = null,
		?MoveToRecycleBinService $moveService = null,
		?SearchIndexService $searchIndexService = null,
		?CollectionImportLockService $importLockService = null,
		?PushNotificationService $pushService = null,
		?RecycleBinRepository $recycleBinRepository = null,
		?DomainEventPublisher $eventPublisher = null,
		?DocumentRepository $documentRepository = null,
		?HardDeleteService $hardDeleteService = null,
		?FavoriteRepository $favoriteRepository = null,
		string $analyticsType = AnalyticsDictionary::TYPE_BK,
		?BacklinkLifecycleNotifier $backlinkNotifier = null,
	)
	{
		$this->analyticsType = $analyticsType;
		$this->id = $id;
		$this->userId = $userId;
		$this->collectionRepository = $collectionRepository ?? new CollectionRepository();
		$this->moveService = $moveService ?? new MoveToRecycleBinService();
		$this->searchIndexService = $searchIndexService ?? new SearchIndexService();
		$this->importLockService = $importLockService ?? new CollectionImportLockService();
		$this->pushService = $pushService ?? new PushNotificationService();
		$this->recycleBinRepository = $recycleBinRepository ?? new RecycleBinRepository();
		$this->eventPublisher = $eventPublisher ?? new DomainEventPublisher();
		$this->documentRepository = $documentRepository ?? new DocumentRepository();
		$this->hardDeleteService = $hardDeleteService ?? new HardDeleteService();
		$this->favoriteRepository = $favoriteRepository ?? new FavoriteRepository();
		$this->backlinkNotifier = $backlinkNotifier ?? new BacklinkLifecycleNotifier();
	}

	protected function execute(): Result
	{
		$collection = $this->collectionRepository->getById($this->id);
		if ($collection === null)
		{
			return $this->createResult(['success' => false]);
		}

		$activeSessionId = $this->importLockService->findActiveSessionForCollection($this->id);
		if ($activeSessionId !== null)
		{
			return $this->createResult([
				'success' => false,
				'errorCode' => 'COLLECTION_UNDER_IMPORT',
				'sessionId' => $activeSessionId,
			]);
		}

		// The main document carries the collection description: it lives outside the tree, so a
		// trashed one could be restored but never shown again. It dies with the collection instead.
		$mainDocumentId = $this->documentRepository->findMainDocumentIdByCollectionId($this->id);

		$connection = Application::getConnection();
		$connection->startTransaction();
		$mainDocumentCleanup = null;
		try
		{
			$trashedIds = $this->moveService->moveCollection($this->id, $this->userId);
			if ($mainDocumentId !== null)
			{
				$mainDocumentCleanup = $this->hardDeleteService->deleteByDocumentIds([$mainDocumentId]);
			}
			// [P3.T2] Favorite rows on the knowledge base itself; the subscription on it is already
			// cleared inside hardDeleteById(). The rows on its documents deliberately stay: those
			// documents are not deleted here, they go to the recycle bin, where RecycleBinRepository
			// ::listVisible() keeps showing them to their owner as orphans and they can be restored
			// into another knowledge base. The row and its position have to survive that, exactly as
			// for any other trashed document; the rows go with the documents themselves, when the bin
			// is emptied or the TTL agent purges it (HardDeleteService::deleteByDocumentIds()).
			$this->favoriteRepository->deleteByCollectionId($this->id);
			$this->collectionRepository->hardDeleteById($this->id);
			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			throw $e;
		}

		// Files and the search index are non-transactional: only touch them once the commit stands.
		if ($mainDocumentCleanup !== null)
		{
			$this->hardDeleteService->runPostCommitCleanup(
				$mainDocumentCleanup['fileIds'],
				$mainDocumentCleanup['documentIds'],
			);
		}

		try
		{
			$this->searchIndexService->deindexDocuments($trashedIds);
		}
		catch (\Throwable)
		{
		}

		$this->emitCollectionDelete($this->id, $trashedIds);
		$this->eventPublisher->emitLifecycle(
			OnDocumentLifecycleEvent::COLLECTION_DELETED,
			$this->id,
			$trashedIds,
		);
		// [D1] trashing the collection changes its documents' visibility; refresh their targets.
		$this->backlinkNotifier->sourcesChanged($trashedIds);

		$this->succeeded = true;

		return $this->createResult([
			'success' => true,
			'trashedIds' => $trashedIds,
		]);
	}

	protected function afterRun(): void
	{
		AnalyticsService::collectionDeleted($this->succeeded, $this->analyticsType);
	}

	private function emitCollectionDelete(int $collectionId, array $trashedDocumentIds): void
	{
		// Skip the map when the cascade falls back to requestRefetch — editors will
		// pick up recycleBinId/trashedAt via the getMyAccess refetch path instead.
		$extra = [];
		if (count($trashedDocumentIds) <= PushNotificationService::REALTIME_BATCH_THRESHOLD)
		{
			$map = $this->recycleBinRepository->getIdsByDocumentIds($trashedDocumentIds);
			if (!empty($map))
			{
				$extra['recycleBinMap'] = $map;
			}
		}

		$this->pushService->emitDocumentCascade(
			collectionId: $collectionId,
			documentIds: $trashedDocumentIds,
			collectionCommand: 'collectionDelete',
			collectionPayloadExtra: $extra,
			globalCommand: 'collectionDelete',
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

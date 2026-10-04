<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Service\Link\BacklinkLifecycleNotifier;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;
use Bitrix\Note\Public\Provider\DocumentProvider;

class RestoreAllArchivedDocumentsCommand extends AbstractCommand
{
	private readonly int $userId;
	private readonly DocumentRepository $repository;
	private readonly DocumentProvider $provider;
	private readonly SearchIndexService $searchIndexService;
	private readonly CollectionRepository $collectionRepository;
	private readonly DomainEventPublisher $eventPublisher;
	private readonly EventLogService $eventLogService;
	private readonly BacklinkLifecycleNotifier $backlinkNotifier;

	public function __construct(
		int $userId,
		?DocumentRepository $repository = null,
		?DocumentProvider $provider = null,
		?SearchIndexService $searchIndexService = null,
		?CollectionRepository $collectionRepository = null,
		?DomainEventPublisher $eventPublisher = null,
		?EventLogService $eventLogService = null,
		?BacklinkLifecycleNotifier $backlinkNotifier = null,
	)
	{
		$this->userId = $userId;
		$this->repository = $repository ?? new DocumentRepository();
		$this->provider = $provider ?? new DocumentProvider();
		$this->searchIndexService = $searchIndexService ?? new SearchIndexService();
		$this->collectionRepository = $collectionRepository ?? new CollectionRepository();
		$this->eventPublisher = $eventPublisher ?? new DomainEventPublisher();
		$this->eventLogService = $eventLogService ?? new EventLogService();
		$this->backlinkNotifier = $backlinkNotifier ?? new BacklinkLifecycleNotifier();
	}

	protected function execute(): Result
	{
		$result = new Result();

		$ids = $this->provider->listArchivedIdsForUserWithManageAccess($this->userId);
		if (empty($ids))
		{
			$result->setData(['restoredCount' => 0]);

			return $result;
		}

		$collectionIds = $this->collectArchivedCollectionIds($ids);

		$this->repository->restoreByIds($ids, $this->userId);
		$restoredCollections = [];
		foreach ($collectionIds as $collectionId)
		{
			if ($this->collectionRepository->restoreById($collectionId))
			{
				$collection = $this->collectionRepository->getById($collectionId);
				if ($collection !== null)
				{
					$restoredCollections[] = $collection;
				}
			}
		}
		$this->repository->liftArchivedOrphansToRoot($ids);

		// Batch history parity with RestoreDocumentCommand; no per-event live-push (see recordMany).
		$this->eventLogService->recordMany(EventTable::SCOPE_DOCUMENT, $ids, 'archive_restored', $this->userId);

		try
		{
			$this->searchIndexService->reindexByIds($ids);
		}
		catch (\Throwable)
		{
		}

		$this->emitRestored($ids);

		$result->setData([
			'restoredCount' => count($ids),
			'restoredCollections' => $restoredCollections,
		]);

		return $result;
	}

	/**
	 * EVENT-NOTE-01 `restored`, one event per owning collection (documents keep their
	 * COLLECTION_ID on restore; only orphaned PARENT_ID is lifted to root).
	 *
	 * @param int[] $documentIds
	 */
	private function emitRestored(array $documentIds): void
	{
		if (empty($documentIds))
		{
			return;
		}

		$rows = DocumentTable::query()
			->setSelect(['ID', 'COLLECTION_ID'])
			->whereIn('ID', $documentIds)
			->fetchAll()
		;

		$idsByCollection = [];
		foreach ($rows as $row)
		{
			$collectionId = (int)$row['COLLECTION_ID'];
			if ($collectionId <= 0)
			{
				continue;
			}
			$idsByCollection[$collectionId][] = (int)$row['ID'];
		}

		foreach ($idsByCollection as $collectionId => $ids)
		{
			$this->eventPublisher->emitLifecycle(
				OnDocumentLifecycleEvent::RESTORED,
				$collectionId,
				$ids,
			);
		}

		// [D1] restoring brings these sources back into view; refresh the backlinks of what they point at.
		$this->backlinkNotifier->sourcesChanged($documentIds);
	}

	/**
	 * @param int[] $documentIds
	 * @return int[] distinct ids of currently archived collections holding the given documents
	 */
	private function collectArchivedCollectionIds(array $documentIds): array
	{
		if (empty($documentIds))
		{
			return [];
		}

		$rows = DocumentTable::query()
			->setSelect(['COLLECTION_ID'])
			->whereIn('ID', $documentIds)
			->where('COLLECTION.IS_ARCHIVED', 'Y')
			->setGroup(['COLLECTION_ID'])
			->fetchAll()
		;

		return array_values(array_filter(
			array_map(static fn(array $row): int => (int)$row['COLLECTION_ID'], $rows),
			static fn(int $id): bool => $id > 0,
		));
	}
}

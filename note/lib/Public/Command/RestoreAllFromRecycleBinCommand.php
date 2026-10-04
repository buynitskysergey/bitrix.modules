<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Exceptions\OrphanRestoreTargetRequiredException;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Service\Link\BacklinkLifecycleNotifier;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Bulk\RestoreOrderer;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\RecycleBin\RestoreFromRecycleBinService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;
use Bitrix\Note\Public\Provider\RecycleBinProvider;

class RestoreAllFromRecycleBinCommand extends AbstractCommand
{
	private const CHUNK_SIZE = 200;

	public function __construct(
		private readonly int $userId,
		private readonly array $accessCodes,
		private readonly ?int $orphanTargetCollectionId = null,
		private readonly RecycleBinProvider $provider = new RecycleBinProvider(),
		private readonly RecycleBinRepository $recycleBinRepository = new RecycleBinRepository(),
		private readonly RestoreFromRecycleBinService $restoreService = new RestoreFromRecycleBinService(),
		private readonly SearchIndexService $searchIndexService = new SearchIndexService(),
		private readonly DomainEventPublisher $eventPublisher = new DomainEventPublisher(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly RestoreOrderer $restoreOrderer = new RestoreOrderer(),
		private readonly EventLogService $eventLogService = new EventLogService(),
		private readonly BacklinkLifecycleNotifier $backlinkNotifier = new BacklinkLifecycleNotifier(),
	) {}

	protected function execute(): Result
	{
		$entryIds = $this->provider->listVisibleRestorableIdsForUser($this->userId, $this->accessCodes);
		$restoredDocumentIds = [];
		// Only documents that actually returned live (not straight back into archive) become RAG members again.
		$restoredLiveIdsByCollection = [];
		$skippedOrphans = 0;

		$orphanTarget = $this->orphanTargetCollectionId !== null && $this->orphanTargetCollectionId > 0
			? $this->orphanTargetCollectionId
			: null
		;

		$normalizedIds = array_values(array_map(static fn($id): int => (int)$id, $entryIds));

		// Load every record + parent map first, then restore ancestors-first so a parent is applied
		// before its children and the tree is rebuilt instead of flattening to the collection root
		// (works for a deleted collection restored into a new target too — see RestoreFromRecycleBinService).
		$recordByDocumentId = [];
		$documentIds = [];
		foreach (array_chunk($normalizedIds, self::CHUNK_SIZE) as $chunk)
		{
			foreach ($this->recycleBinRepository->getByIds($chunk) as $record)
			{
				$documentId = (int)$record->getDocumentId();
				if ($documentId > 0)
				{
					$recordByDocumentId[$documentId] = $record;
					$documentIds[] = $documentId;
				}
			}
		}

		$metaById = [];
		foreach (array_chunk($documentIds, self::CHUNK_SIZE) as $chunk)
		{
			foreach ($this->documentRepository->getMetaByIds($chunk) as $document)
			{
				$parentId = $document->getParentId();
				$metaById[(int)$document->getId()] = [
					'parentId' => $parentId !== null ? (int)$parentId : null,
				];
			}
		}

		foreach ($this->restoreOrderer->order($documentIds, $metaById) as $documentId)
		{
			$record = $recordByDocumentId[$documentId] ?? null;
			if ($record === null)
			{
				continue;
			}

			$connection = Application::getConnection();
			$connection->startTransaction();
			try
			{
				try
				{
					$result = $this->restoreService->restore($record, null, $this->userId);
				}
				catch (OrphanRestoreTargetRequiredException $orphanException)
				{
					if ($orphanTarget === null)
					{
						throw $orphanException;
					}

					$result = $this->restoreService->restore($record, $orphanTarget, $this->userId);
				}

				if (!$result->isSuccess())
				{
					$connection->rollbackTransaction();

					continue;
				}
				$connection->commitTransaction();
				$restoredDocumentIds[] = $documentId;

				// Documents that were live before being trashed are grouped per collection for the
				// lifecycle event emitted below; the ones archived before trashing stay out of RAG.
				$data = $result->getData();
				if (($data['wasArchivedBeforeTrash'] ?? false) !== true)
				{
					$collectionId = (int)($data['collectionId'] ?? 0);
					if ($collectionId > 0)
					{
						$restoredLiveIdsByCollection[$collectionId][] = $documentId;
					}
				}
			}
			catch (OrphanRestoreTargetRequiredException)
			{
				$connection->rollbackTransaction();
				$skippedOrphans++;
			}
			catch (\Throwable $e)
			{
				$connection->rollbackTransaction();
				throw $e;
			}
		}

		if (!empty($restoredDocumentIds))
		{
			// Batch history parity with RestoreDocumentFromRecycleBinCommand; no live-push (see recordMany).
			$this->eventLogService->recordMany(EventTable::SCOPE_DOCUMENT, $restoredDocumentIds, 'trash_restored', $this->userId);

			try
			{
				$this->searchIndexService->reindexByIds($restoredDocumentIds);
			}
			catch (\Throwable)
			{
			}
		}

		// EVENT-NOTE-01 `restored`, grouped per owning collection; archive-bound restores excluded.
		$restoredLiveIds = [];
		foreach ($restoredLiveIdsByCollection as $collectionId => $ids)
		{
			$this->eventPublisher->emitLifecycle(
				OnDocumentLifecycleEvent::RESTORED,
				$collectionId,
				$ids,
			);
			$restoredLiveIds = [...$restoredLiveIds, ...$ids];
		}

		// [D1] refresh the backlinks of the documents these restored sources point at.
		$this->backlinkNotifier->sourcesChanged($restoredLiveIds);

		$result = new Result();
		$result->setData([
			'restoredCount' => count($restoredDocumentIds),
			'skippedOrphans' => $skippedOrphans,
		]);

		return $result;
	}
}

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
use Bitrix\Note\Internal\Service\RecycleBin\ModeratedTrashedCollections;
use Bitrix\Note\Internal\Service\RecycleBin\RestoreFromRecycleBinService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;
use Bitrix\Note\Public\Provider\RecycleBinProvider;

class RestoreAllFromRecycleBinCommand extends AbstractCommand
{
	/** A base that refused to come back for a reason of its own that it did not name. */
	public const ERROR_COLLECTION_RESTORE_FAILED = 'COLLECTION_RESTORE_FAILED';

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
		private readonly ModeratedTrashedCollections $trashedCollections = new ModeratedTrashedCollections(),
	) {}

	protected function execute(): Result
	{
		// The bases come back first. While a base lies in the bin its own documents have nowhere to
		// return to - the pre-flight of a restore refuses such a destination - so every row of theirs
		// would be counted as an orphan and either skipped or dragged into a foreign base. Once the
		// base is out, the rows left in the bin are the ones thrown away inside it on their own, and
		// they go back the ordinary way in the pass below.
		$collections = $this->restoreCollections();

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

				// Documents that came back live are grouped per collection for the lifecycle event
				// emitted below; the ones that came back archived stay out of RAG.
				$data = $result->getData();
				if (($data['restoredArchived'] ?? false) !== true)
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
			'restoredCollectionCount' => count($collections['restored']),
			// Bases the caller may act on that did not come back all the same: past their retention,
			// already being destroyed, or held by somebody else's operation. Named one by one with the
			// reason, so a partial run is visible instead of being hidden inside a total.
			'failedCollections' => $collections['failed'],
		]);

		return $result;
	}

	/**
	 * Brings back every base of the bin this caller may act on. One base failing is not the end of
	 * the run: the bin holds bases of many people and one of them being purged right now must not
	 * keep the rest where they are.
	 *
	 * @return array{restored: int[], failed: array<int, array{collectionId: int, errorCode: string}>}
	 */
	private function restoreCollections(): array
	{
		$restored = [];
		$failed = [];

		foreach ($this->trashedCollections->iterate($this->userId, $this->accessCodes) as $collectionId)
		{
			try
			{
				$result = (new RestoreCollectionFromRecycleBinCommand($collectionId, $this->userId))->run();
			}
			catch (\Throwable)
			{
				$failed[] = [
					'collectionId' => $collectionId,
					'errorCode' => self::ERROR_COLLECTION_RESTORE_FAILED,
				];

				continue;
			}

			$data = $result->getData();
			if (($data['success'] ?? false) === true)
			{
				$restored[] = $collectionId;

				continue;
			}

			$failed[] = [
				'collectionId' => $collectionId,
				'errorCode' => (string)($data['errorCode'] ?? self::ERROR_COLLECTION_RESTORE_FAILED),
			];
		}

		return ['restored' => $restored, 'failed' => $failed];
	}
}

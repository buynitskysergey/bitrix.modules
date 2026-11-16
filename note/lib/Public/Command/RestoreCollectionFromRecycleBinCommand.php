<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Model\RecycleBinTable;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Collection\CollectionLockService;
use Bitrix\Note\Internal\Service\Collection\CollectionRestoreService;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Service\Link\BacklinkLifecycleNotifier;
use Bitrix\Note\Internal\Service\RecycleBin\RetentionDeadline;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;

/**
 * Brings a knowledge base back from the recycle bin with the tree it had when it was deleted.
 * Deleting never mutated the documents, so dropping their bin rows is the whole restore - not
 * RestoreFromRecycleBinService, which is built for a foreign tree and recalculates every position.
 * Only ORIGIN_CASCADE_COLLECTION_DELETED rows go back: what was thrown away earlier stays in the bin.
 *
 * The search index is not touched here either: the documents stayed indexed while the base lay in
 * the bin, hidden by the state gate of the base alone (see DeleteCollectionCommand), so the base is
 * searchable again the moment its state flips.
 */
class RestoreCollectionFromRecycleBinCommand extends AbstractCommand
{
	public const ERROR_RETENTION_EXPIRED = 'COLLECTION_RETENTION_EXPIRED';
	public const ERROR_RESTORE_IN_PROGRESS = 'COLLECTION_RESTORE_IN_PROGRESS';
	public const ERROR_PURGE_STARTED = 'COLLECTION_PURGE_STARTED';

	private const CHUNK_SIZE = 500;

	public function __construct(
		private readonly int $id,
		private readonly int $userId = 0,
		private readonly CollectionRepository $collectionRepository = new CollectionRepository(),
		private readonly CollectionRestoreService $collectionRestoreService = new CollectionRestoreService(),
		private readonly CollectionLockService $lockService = new CollectionLockService(),
		private readonly RecycleBinRepository $recycleBinRepository = new RecycleBinRepository(),
		private readonly PushNotificationService $pushService = new PushNotificationService(),
		private readonly DomainEventPublisher $eventPublisher = new DomainEventPublisher(),
		private readonly BacklinkLifecycleNotifier $backlinkNotifier = new BacklinkLifecycleNotifier(),
	) {}

	protected function execute(): Result
	{
		if (!$this->lockService->acquire($this->id))
		{
			return $this->createResult([
				'success' => false,
				'errorCode' => self::ERROR_RESTORE_IN_PROGRESS,
			]);
		}

		try
		{
			return $this->restoreUnderLock();
		}
		finally
		{
			$this->lockService->release($this->id);
		}
	}

	private function restoreUnderLock(): Result
	{
		$collection = $this->collectionRepository->getById($this->id);
		if ($collection === null)
		{
			return $this->createResult(['success' => false]);
		}

		// Before anything is touched. A base that is not in the bin is the state the caller asked for,
		// so the answer is the same as for one restored by somebody else a moment earlier - but the
		// lifting below must not run for it: it would strip the bin rows of the documents thrown away
		// inside a live base, one by one and without putting them back in the tree.
		if ($collection->getTrashedAt() === null)
		{
			return $this->createResult(['success' => true, 'restoredCount' => 0]);
		}

		// Checked before the transition: the cleanup agent purges the documents in an earlier pass than
		// the base, so past the retention a restore would hand back an empty base.
		if (RetentionDeadline::hasPassed($collection->getTrashedAt()))
		{
			return $this->createResult([
				'success' => false,
				'errorCode' => self::ERROR_RETENTION_EXPIRED,
			]);
		}

		// Destruction for good has begun on this base, and it is committed chunk by chunk: whatever is
		// left of the base is a part of it, and no restore makes it whole again. The lock says nothing
		// here - it is held for a tick of the purge and is free between the ticks.
		if ($collection->getPurgeStartedAt() !== null)
		{
			return $this->createResult([
				'success' => false,
				'errorCode' => self::ERROR_PURGE_STARTED,
			]);
		}

		// The tree leaves the bin before the base does, and each chunk stands on its own rather than
		// inside one transaction over the whole base. Until the state of the base flips it is hidden
		// whole, so a run cut short by the time limit leaves nothing half-visible: the base is still
		// in the bin and repeating the restore carries on from the documents still in it - including
		// the announcements, which a chunk makes for itself right after it is lifted.
		$restoredCount = $this->liftDocumentsOutOfBin();

		$transition = $this->collectionRestoreService->restoreFromRecycleBin($this->id);
		// Already restored by someone else: the caller gets the state it asked for, not an error.
		if (($transition->getData()['transitioned'] ?? false) !== true)
		{
			return $this->createResult(['success' => true, 'restoredCount' => 0]);
		}

		return $this->createResult([
			'success' => true,
			'restoredCount' => $restoredCount,
		]);
	}

	/**
	 * Drops the bin rows of the documents that went there with the base, in chunks like moveCollection,
	 * and announces every chunk as soon as it is out.
	 *
	 * A base holds as many documents as it holds, so nothing here may grow with it: a chunk carries
	 * its own ids and lets go of them. Announcing per chunk is also what makes an interrupted restore
	 * whole - the deletion of the rows is committed chunk by chunk, so a set of ids gathered for the
	 * end would be lost with the process that died holding it, and the repeated restore would read
	 * only the rows still in the bin and announce only those.
	 *
	 * @return int how many documents of the tree came back
	 */
	private function liftDocumentsOutOfBin(): int
	{
		$restoredCount = 0;
		$afterDocumentId = 0;

		do
		{
			$rows = $this->recycleBinRepository->listByCollectionAndOrigin(
				$this->id,
				RecycleBinTable::ORIGIN_CASCADE_COLLECTION_DELETED,
				$afterDocumentId,
				self::CHUNK_SIZE,
			);
			if (empty($rows))
			{
				break;
			}

			$documentIds = [];
			$restoredIds = [];
			$liveIds = [];
			foreach ($rows as $row)
			{
				$documentId = $row['documentId'];
				$documentIds[] = $documentId;
				$afterDocumentId = $documentId;

				// The main document comes back too, but it lives outside the tree and the search index.
				if ($row['isMain'])
				{
					continue;
				}

				$restoredIds[] = $documentId;
				if (!$row['isArchived'])
				{
					$liveIds[] = $documentId;
				}
			}

			$this->recycleBinRepository->deleteByDocumentIds($documentIds);
			$this->announceChunk($restoredIds, $liveIds, $documentIds);
			$restoredCount += count($restoredIds);
		}
		while (count($rows) === self::CHUNK_SIZE);

		return $restoredCount;
	}

	/**
	 * @param int[] $restoredIds documents of the tree this chunk brought back
	 * @param int[] $liveIds those of them that came back live rather than archived
	 * @param int[] $liftedIds everything the chunk lifted, the main document included
	 */
	private function announceChunk(array $restoredIds, array $liveIds, array $liftedIds): void
	{
		$this->emitCollectionRestore($restoredIds);

		if (!empty($liveIds))
		{
			$this->eventPublisher->emitLifecycle(
				OnDocumentLifecycleEvent::RESTORED,
				$this->id,
				$liveIds,
			);
		}

		// The documents are visible again; refresh the backlinks of what they point at. Everything
		// lifted out of the bin, not just the live part: the archived documents and the base
		// description went dark on deletion too.
		if (!empty($liftedIds))
		{
			$this->backlinkNotifier->sourcesChanged($liftedIds);
		}
	}

	/**
	 * @param int[] $restoredDocumentIds
	 */
	private function emitCollectionRestore(array $restoredDocumentIds): void
	{
		if (empty($restoredDocumentIds))
		{
			return;
		}

		// The base itself is announced globally by CollectionRestoreService; this is for the open tree.
		$this->pushService->emitDocumentCascade(
			collectionId: $this->id,
			documentIds: $restoredDocumentIds,
			collectionCommand: 'collectionRestoreFromBin',
			initiatorUserId: $this->userId,
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

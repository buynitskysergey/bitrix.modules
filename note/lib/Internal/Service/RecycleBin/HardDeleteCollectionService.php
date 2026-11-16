<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\RecycleBin;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\FavoriteRepository;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Collection\CollectionLockService;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;

/**
 * Removes a knowledge base for good: the documents first, the collection row last. The reverse order
 * would turn the documents into orphans inside their own cleanup.
 *
 * One base is one call of this, whether it comes from the cleanup agent, from emptying the bin or
 * from the page of the base itself.
 */
class HardDeleteCollectionService
{
	public const ERROR_LOCKED = 'COLLECTION_HARD_DELETE_IN_PROGRESS';
	public const ERROR_NOT_TRASHED = 'COLLECTION_NOT_TRASHED';

	private const CHUNK_SIZE = 100;

	public function __construct(
		private readonly CollectionRepository $collectionRepository = new CollectionRepository(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly FavoriteRepository $favoriteRepository = new FavoriteRepository(),
		private readonly HardDeleteService $hardDeleteService = new HardDeleteService(),
		private readonly CollectionLockService $lockService = new CollectionLockService(),
		private readonly DomainEventPublisher $eventPublisher = new DomainEventPublisher(),
		private readonly PushNotificationService $pushService = new PushNotificationService(),
	) {}

	/**
	 * @param float|null $deadline microtime(true) mark the purge stops at, between chunks: the base
	 *        keeps whatever is left and stays in the bin. Null takes as long as the base needs.
	 * @return Result data: deletedCount - documents removed; completed - false when the deadline cut
	 *         the purge short and the base is still there; error ERROR_LOCKED when the base is busy
	 *         (being restored or purged elsewhere) and has to be left for the next run;
	 *         error ERROR_NOT_TRASHED when the base is alive - only the bin leads here
	 */
	public function purge(int $collectionId, ?float $deadline = null): Result
	{
		$result = new Result();
		if ($collectionId <= 0)
		{
			$result->setData(['deletedCount' => 0, 'completed' => true]);

			return $result;
		}

		if (!$this->lockService->acquire($collectionId))
		{
			$result->addError(new Error('Knowledge base is busy', self::ERROR_LOCKED));

			return $result;
		}

		try
		{
			if (!$this->collectionRepository->exists($collectionId))
			{
				$result->setData(['deletedCount' => 0, 'completed' => true]);

				return $result;
			}

			// The state and not the caller decides: this is a public entry point, and a live base
			// reached by it would be destroyed with nothing to undo it.
			if (!$this->collectionRepository->isTrashed($collectionId))
			{
				$result->addError(new Error('Knowledge base is not in the recycle bin', self::ERROR_NOT_TRASHED));

				return $result;
			}

			// Before the first document goes. Each chunk is committed on its own, so a run cut short by
			// the deadline leaves a base that has already lost part of its documents for good; the mark
			// is what a later restore reads to refuse, and unlike the lock - held for the tick only -
			// it stays on the row until the row itself goes away.
			$this->collectionRepository->markPurgeStarted($collectionId);

			$purge = $this->purgeDocuments($collectionId, $deadline);
			if ($purge['completed'])
			{
				$this->purgeCollectionRow($collectionId);
			}
		}
		finally
		{
			$this->lockService->release($collectionId);
		}

		if (!$purge['completed'])
		{
			// Every chunk is committed on its own, so the base is simply smaller now: whoever runs next
			// carries on from the documents still there. Nothing is announced - the base has not gone.
			$result->setData(['deletedCount' => $purge['deletedCount'], 'completed' => false]);

			return $result;
		}

		// The documents announced themselves when they went to the bin; this says the base is gone for
		// good, so a subscriber does not read the trip to the bin as the end of it.
		$this->eventPublisher->emitLifecycle(
			OnDocumentLifecycleEvent::COLLECTION_HARD_DELETED,
			$collectionId,
			[],
		);
		$this->emitHardDelete($collectionId);

		$result->setData(['deletedCount' => $purge['deletedCount'], 'completed' => true]);

		return $result;
	}

	/**
	 * The pair of collectionDelete: that one says "in the bin, restorable", this one says the base is
	 * gone, so a recycle bin list or a page opened from it does not keep offering a restore.
	 */
	private function emitHardDelete(int $collectionId): void
	{
		$pushService = $this->pushService;
		$payload = ['collectionId' => $collectionId];

		$pushService->dispatchAfterCommit(static function () use ($pushService, $collectionId, $payload): void {
			$pushService->sendToCollection($collectionId, 'collectionHardDelete', $payload);
			$pushService->sendGlobal('collectionHardDelete', $payload);
		});
	}

	/**
	 * Every document of the base, whether it reached the bin or not, in chunks: files, versions,
	 * events, views, subscriptions, favorites, ACL and the search index go with them.
	 *
	 * @return array{deletedCount: int, completed: bool}
	 */
	private function purgeDocuments(int $collectionId, ?float $deadline): array
	{
		$deletedCount = 0;
		$afterId = 0;

		while (true)
		{
			$rows = $this->documentRepository->listByCollectionForCascadeDelete(
				$collectionId,
				$afterId,
				self::CHUNK_SIZE,
				includeMain: true,
			);
			if (empty($rows))
			{
				break;
			}

			$documentIds = [];
			foreach ($rows as $row)
			{
				$documentIds[] = (int)$row['ID'];
				$afterId = (int)$row['ID'];
			}

			// Reads both directions of the link graph before the transaction opens, as the service requires.
			$preparedLinkChange = $this->hardDeleteService->prepareDelete($documentIds);

			$connection = Application::getConnection();
			$connection->startTransaction();
			try
			{
				$cleanupPayload = $this->hardDeleteService->deleteByDocumentIds($documentIds, $preparedLinkChange);
				$connection->commitTransaction();
			}
			catch (\Throwable $e)
			{
				$connection->rollbackTransaction();

				throw $e;
			}

			$this->hardDeleteService->runPostCommitCleanup(
				$cleanupPayload['fileIds'],
				$cleanupPayload['documentIds'],
			);

			$deletedCount += count($documentIds);

			if (count($rows) < self::CHUNK_SIZE)
			{
				break;
			}

			if ($deadline !== null && microtime(true) > $deadline)
			{
				return ['deletedCount' => $deletedCount, 'completed' => false];
			}
		}

		return ['deletedCount' => $deletedCount, 'completed' => true];
	}

	/**
	 * hardDeleteById clears the ACL of the base, its import map and its collection-scope
	 * subscriptions; favorites pointing at the base have no other cleanup hook.
	 */
	private function purgeCollectionRow(int $collectionId): void
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$this->collectionRepository->hardDeleteById($collectionId);
			$this->favoriteRepository->deleteByCollectionId($collectionId);
			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}
	}
}

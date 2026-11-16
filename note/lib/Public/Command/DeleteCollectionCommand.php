<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Service\ExternalAccess\ExternalAccessLifecycleService;
use Bitrix\Note\Internal\Service\ExternalAccess\RevokePullService;
use Bitrix\Note\Internal\Service\Link\BacklinkLifecycleNotifier;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsDictionary;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsService;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Import\CollectionImportLockService;
use Bitrix\Note\Internal\Service\RecycleBin\MoveToRecycleBinService;

class DeleteCollectionCommand extends AbstractCommand
{
	private readonly int $id;
	private readonly int $userId;
	private readonly CollectionRepository $collectionRepository;
	private readonly MoveToRecycleBinService $moveService;
	private readonly CollectionImportLockService $importLockService;
	private readonly PushNotificationService $pushService;
	private readonly RecycleBinRepository $recycleBinRepository;
	private readonly DomainEventPublisher $eventPublisher;
	private readonly BacklinkLifecycleNotifier $backlinkNotifier;
	private readonly ExternalAccessLifecycleService $externalAccess;
	private readonly RevokePullService $revokePullService;
	private readonly string $analyticsType;
	// Failure is signalled via data['success']=false, so $result->isSuccess() is unreliable here.
	private bool $succeeded = false;

	public function __construct(
		int $id,
		int $userId = 0,
		?CollectionRepository $collectionRepository = null,
		?MoveToRecycleBinService $moveService = null,
		?CollectionImportLockService $importLockService = null,
		?PushNotificationService $pushService = null,
		?RecycleBinRepository $recycleBinRepository = null,
		?DomainEventPublisher $eventPublisher = null,
		string $analyticsType = AnalyticsDictionary::TYPE_BK,
		?BacklinkLifecycleNotifier $backlinkNotifier = null,
		?ExternalAccessLifecycleService $externalAccess = null,
		?RevokePullService $revokePullService = null,
	)
	{
		$this->analyticsType = $analyticsType;
		$this->id = $id;
		$this->userId = $userId;
		$this->collectionRepository = $collectionRepository ?? new CollectionRepository();
		$this->moveService = $moveService ?? new MoveToRecycleBinService();
		$this->importLockService = $importLockService ?? new CollectionImportLockService();
		$this->pushService = $pushService ?? new PushNotificationService();
		$this->recycleBinRepository = $recycleBinRepository ?? new RecycleBinRepository();
		$this->eventPublisher = $eventPublisher ?? new DomainEventPublisher();
		$this->backlinkNotifier = $backlinkNotifier ?? new BacklinkLifecycleNotifier();
		$this->externalAccess = $externalAccess ?? new ExternalAccessLifecycleService();
		$this->revokePullService = $revokePullService ?? new RevokePullService();
	}

	protected function execute(): Result
	{
		$collection = $this->collectionRepository->getById($this->id);
		if ($collection === null)
		{
			return $this->createResult(['success' => false]);
		}

		// Already in the bin: answered before the move rather than after it, so a repeat does not walk
		// the tree again and does not re-announce a deletion that has already happened.
		if ($collection->getTrashedAt() !== null)
		{
			$this->succeeded = true;

			return $this->createResult(['success' => true, 'trashedIds' => []]);
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

		// The revoke command is addressed by the link record id, and the scope row that ties the
		// record to this knowledge base dies below: read the id while it is still readable.
		$linkRecordId = $this->externalAccess->findLinkId($this->id);

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			if ($linkRecordId !== null)
			{
				$this->externalAccess->disableLink($linkRecordId);
				$this->externalAccess->deleteScopeRows($this->id);
			}

			// The main document rides along with the rest: nothing is destroyed here any more, so
			// the collection description has somewhere to come back to.
			$trashedIds = $this->moveService->moveCollection($this->id, $this->userId);

			// Favorite rows and collection-scope subscriptions stay. While the base is restorable,
			// so is the surroundings a person built around it; both are cleared for good by
			// HardDeleteCollectionService, when the bin is emptied or the TTL agent purges it.
			$trashed = $this->collectionRepository->trashById($this->id, $this->userId);
			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			throw $e;
		}

		// Deleting a base that is already in the recycle bin is an ordinary race between two tabs,
		// not a failure: the state the caller asked for is the state it gets.
		if (!$trashed)
		{
			$this->succeeded = true;

			return $this->createResult([
				'success' => true,
				'trashedIds' => $trashedIds,
			]);
		}

		// The search index is left alone: nothing about the documents changed, only the state of the
		// base, and every ranked read already gates its query by that state (see
		// \Bitrix\Note\Internal\Access\Service\DocumentAccessService::buildCollectionStateFilter()).
		// That is what keeps the restore a state flip instead of a reindex of the whole base; the
		// rows go with the documents when the base is purged for good.
		$this->emitCollectionDelete($this->id, $trashedIds);
		$this->eventPublisher->emitLifecycle(
			OnDocumentLifecycleEvent::COLLECTION_DELETED,
			$this->id,
			$trashedIds,
		);
		// [D1] trashing the collection changes its documents' visibility; refresh their targets.
		$this->backlinkNotifier->sourcesChanged($trashedIds);

		if ($linkRecordId !== null)
		{
			$this->publishRevoke($linkRecordId);
		}

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
		// restorable says the base went to the bin instead of dying, so an open editor tells the
		// difference without asking the server (AC-016). Every recipient of the cascade gets it.
		$extra = ['restorable' => true];

		// Skip the map when the cascade falls back to requestRefetch — editors will
		// pick up recycleBinId/trashedAt via the getMyAccess refetch path instead.
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
			globalPayload: ['collectionId' => $collectionId, 'restorable' => true],
			granteePayloadExtra: ['restorable' => true],
		);
	}

	/**
	 * The revoke command is irreversible: sent from an unfinished transaction it would declare the
	 * access closed while a rollback keeps the link working. A background job runs at the very end
	 * of the request, when the transaction of a caller wrapping this command is closed too.
	 */
	private function publishRevoke(int $linkRecordId): void
	{
		$service = $this->revokePullService;
		Application::getInstance()->addBackgroundJob(static function () use ($service, $linkRecordId): void {
			$service->publishRevoke($linkRecordId);
		});
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

<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Exceptions\OrphanRestoreTargetRequiredException;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Service\Link\BacklinkLifecycleNotifier;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\RecycleBin\RestoreFromRecycleBinService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;

class RestoreDocumentFromRecycleBinCommand extends AbstractCommand
{
	public const ERROR_RECORD_MISSING = 'NOTE_RECYCLE_BIN_RECORD_MISSING';

	public function __construct(
		private readonly int $entryId,
		private readonly int $userId,
		private readonly ?int $targetCollectionId = null,
		private readonly RecycleBinRepository $recycleBinRepository = new RecycleBinRepository(),
		private readonly RestoreFromRecycleBinService $restoreService = new RestoreFromRecycleBinService(),
		private readonly SearchIndexService $searchIndexService = new SearchIndexService(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly PushNotificationService $pushService = new PushNotificationService(),
		private readonly DomainEventPublisher $eventPublisher = new DomainEventPublisher(),
		private readonly EventLogService $eventLogService = new EventLogService(),
		private readonly BacklinkLifecycleNotifier $backlinkNotifier = new BacklinkLifecycleNotifier(),
	) {}

	protected function execute(): Result
	{
		$record = $this->recycleBinRepository->getById($this->entryId);
		if ($record === null)
		{
			$result = new Result();
			$result->addError(new Error('Recycle-bin record not found', self::ERROR_RECORD_MISSING));

			return $result;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$result = $this->restoreService->restore($record, $this->targetCollectionId, $this->userId);
		}
		catch (OrphanRestoreTargetRequiredException $e)
		{
			// restore() throws this before any write (only lookups happened so far): nothing
			// to undo. Commit the empty transaction rather than roll it back — Bitrix doesn't
			// support nested rollback when this command runs inside an already-open
			// transaction (e.g. tests), but nested commit is always safe.
			$connection->commitTransaction();

			throw $e;
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			throw $e;
		}

		if (!$result->isSuccess())
		{
			// restore() only fails (document missing) before any write — nothing to undo.
			$connection->commitTransaction();

			return $result;
		}

		$connection->commitTransaction();

		// Event on the operation's root only (the restored document), not per subtree descendant.
		// Recorded best-effort after commit: a failure here must not undo the already-committed
		// restore, matching MoveDocumentCommand and the rest of the Block 0 commands.
		if (Configuration::isActivityEnabled())
		{
			try
			{
				$data = $result->getData();
				$documentId = (int)($data['documentId'] ?? $record->getDocumentId());
				$this->eventLogService->record(EventTable::SCOPE_DOCUMENT, $documentId, 'trash_restored', $this->userId);
			}
			catch (\Throwable)
			{
			}
		}

		try
		{
			$this->searchIndexService->indexDocument((int)$record->getDocumentId());
		}
		catch (\Throwable)
		{
		}

		$data = $result->getData();
		$wasArchivedBeforeTrash = (bool)($data['wasArchivedBeforeTrash'] ?? false);
		if (!$wasArchivedBeforeTrash)
		{
			$documentId = (int)($data['documentId'] ?? $record->getDocumentId());
			$collectionId = (int)($data['collectionId'] ?? 0);
			$this->emitDocumentRestore(
				$documentId,
				$collectionId,
				isset($data['parentId']) ? (int)$data['parentId'] : null,
				(int)($data['position'] ?? 0),
			);
			$this->eventPublisher->emitLifecycle(
				OnDocumentLifecycleEvent::RESTORED,
				$collectionId,
				[$documentId],
			);
			// [D1] restoring brings this source back into view; refresh the backlinks of what it points at.
			$this->backlinkNotifier->sourcesChanged([$documentId]);
		}

		return $result;
	}

	private function emitDocumentRestore(int $documentId, int $collectionId, ?int $parentId, int $position): void
	{
		$initiatorUserId = $this->userId;
		$pushService = $this->pushService;
		$documentRepository = $this->documentRepository;

		// Grantees need the same node data as the collection channel, but their fan-out resolves
		// recipients synchronously — hence the title/hasChildren read outside the deferred job.
		$restored = $this->documentRepository->getById($documentId);
		$hasChildrenMap = $this->documentRepository->getHasChildrenMap($collectionId, [$documentId]);
		$pushService->notifyDocumentGrantees(
			$collectionId,
			[$documentId],
			$initiatorUserId,
			'documentRestore',
			[
				'documentId' => $documentId,
				'parentId' => $parentId,
				'position' => $position,
				'title' => $restored !== null ? (string)$restored->getTitle() : '',
				'hasChildren' => (bool)($hasChildrenMap[$documentId] ?? false),
			],
		);

		$pushService->dispatchAfterCommit(static function () use (
			$pushService, $documentRepository, $documentId, $collectionId, $parentId, $position, $initiatorUserId,
		): void {
			$document = $documentRepository->getById($documentId);
			$title = $document !== null ? (string)$document->getTitle() : '';
			$hasChildrenMap = $documentRepository->getHasChildrenMap($collectionId, [$documentId]);
			$hasChildren = (bool)($hasChildrenMap[$documentId] ?? false);

			$payload = [
				'documentId' => $documentId,
				'collectionId' => $collectionId,
				'parentId' => $parentId,
				'position' => $position,
				'title' => $title,
				'hasChildren' => $hasChildren,
			];

			$pushService->sendToCollection($collectionId, 'documentRestore', $payload, $initiatorUserId);
			$pushService->sendToDocument($documentId, 'documentRestore', $payload, $initiatorUserId);
		});
	}
}

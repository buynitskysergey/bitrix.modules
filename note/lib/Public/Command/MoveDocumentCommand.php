<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Exceptions\DocumentArchivedException;
use Bitrix\Note\Internal\Exceptions\DocumentInRecycleBinException;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;
use Bitrix\Note\Internal\Exceptions\MainDocumentOperationException;
use Bitrix\Note\Internal\Exceptions\MoveAccessEscalationException;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Document\Position\PositionService;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\RecycleBin\RecycleBinFilter;

class MoveDocumentCommand extends AbstractCommand
{
	private readonly int $id;
	private readonly int $collectionId;
	private readonly ?int $parentId;
	private readonly ?int $position;
	private readonly int $userId;
	private readonly PositionService $positionService;
	private readonly DocumentRepository $repository;
	private readonly RecycleBinFilter $recycleBinFilter;
	private readonly PushNotificationService $pushService;
	private readonly DomainEventPublisher $eventPublisher;
	private readonly EventLogService $eventLogService;

	public function __construct(
		int $id,
		int $collectionId,
		?int $parentId,
		?int $position,
		int $userId,
		?PositionService $positionService = null,
		?DocumentRepository $repository = null,
		?RecycleBinFilter $recycleBinFilter = null,
		?PushNotificationService $pushService = null,
		?DomainEventPublisher $eventPublisher = null,
		?EventLogService $eventLogService = null,
	)
	{
		$this->id = $id;
		$this->collectionId = $collectionId;
		$this->parentId = $parentId;
		$this->position = $position;
		$this->userId = $userId;
		$this->positionService = $positionService ?? new PositionService();
		$this->repository = $repository ?? new DocumentRepository();
		$this->recycleBinFilter = $recycleBinFilter ?? new RecycleBinFilter();
		$this->pushService = $pushService ?? new PushNotificationService();
		$this->eventPublisher = $eventPublisher ?? new DomainEventPublisher();
		$this->eventLogService = $eventLogService ?? new EventLogService();
	}

	protected function execute(): Result
	{
		// The main document has no place in the tree (PARENT_ID is always NULL) and must
		// never be moved or re-parented.
		if ($this->repository->isMainDocument($this->id))
		{
			throw new MainDocumentOperationException();
		}

		if ($this->recycleBinFilter->isInRecycleBin($this->id))
		{
			throw new DocumentInRecycleBinException();
		}

		$document = $this->repository->getById($this->id);
		if ($document !== null && $document->getIsArchived())
		{
			throw new DocumentArchivedException();
		}

		// Uncached pre-move snapshot of the source location so the domain event's "from" is reliable
		// (the cached getById above may be stale, which could otherwise drop the move event).
		$sourceLocation = $this->repository->getLocationById($this->id);
		$sourceCollectionId = $sourceLocation['collectionId'] ?? null;
		$sourceParentId = $sourceLocation['parentId'] ?? null;

		if ($this->parentId !== null)
		{
			// The main document lives outside the tree and can never be a parent:
			// re-parenting anything under it would break the "About the knowledge base" tab.
			if ($this->repository->isMainDocument($this->parentId))
			{
				throw new MainDocumentOperationException();
			}

			if ($this->recycleBinFilter->isInRecycleBin($this->parentId))
			{
				throw new DocumentInRecycleBinException();
			}

			$parent = $this->repository->getById($this->parentId);
			if ($parent !== null && $parent->getIsArchived())
			{
				throw new DocumentArchivedException();
			}
		}

		$result = $this->positionService->move(
			$this->id,
			$this->collectionId,
			$this->parentId,
			$this->position,
			$this->userId,
		);
		if (!$result->isSuccess())
		{
			// Preserve the typed escalation code (Result → SystemException would flatten it to
			// text): the controller needs it to tell the frontend "needs moderator" apart.
			if ($this->hasEscalationError($result))
			{
				throw new MoveAccessEscalationException();
			}

			throw new SystemException($this->buildSaveErrorMessage($result, 'Unable to move document.'));
		}

		$data = $result->getData();
		$document = $data['document'] ?? null;
		$affectedPositions = $data['affectedPositions'] ?? [];

		if ($document === null)
		{
			return $this->createResult(['document' => null, 'affectedPositions' => $affectedPositions]);
		}

		if (!$document instanceof Document)
		{
			throw new SystemException('Unable to move document.');
		}

		// PositionService returns a meta-only Document (ID/COLLECTION_ID/PARENT_ID/POSITION).
		// Reload full record once — reused both for the push payload (title) and the action response.
		$fullDocument = $this->repository->getById((int)$document->getId());

		// PositionService::runMoveWithLocks already committed its own transaction above:
		// the event write happens right after that commit rather than inside it (recording
		// history here is best-effort and must not turn an already-successful move into a
		// command failure).
		if (Configuration::isActivityEnabled())
		{
			try
			{
				$this->eventLogService->record(EventTable::SCOPE_DOCUMENT, (int)$document->getId(), 'moved', $this->userId);
			}
			catch (\Throwable)
			{
			}
		}

		$this->emitDocumentMove($document, $fullDocument, $affectedPositions, $sourceCollectionId, $sourceParentId);
		$this->emitMoveDomainEvent($document, $sourceCollectionId, $sourceParentId);

		return $this->createResult([
			'document' => $document,
			'fullDocument' => $fullDocument,
			'affectedPositions' => $affectedPositions,
		]);
	}

	private function emitDocumentMove(
		Document $document,
		?Document $fullDocument,
		array $affectedPositions,
		?int $sourceCollectionId,
		?int $sourceParentId,
	): void
	{
		$documentId = (int)$document->getId();
		$targetCollectionId = (int)$document->getCollectionId();
		$targetParentId = $document->getParentId();
		$finalPosition = (int)$document->getPosition();
		$title = $fullDocument !== null ? (string)$fullDocument->getTitle() : '';

		$hasChildren = $this->repository->hasChildren($targetCollectionId, $documentId);
		$fromParentHasChildren = ($sourceParentId !== null && $sourceCollectionId !== null)
			? $this->repository->hasChildren($sourceCollectionId, $sourceParentId)
			: null;
		$initiatorUserId = $this->userId;
		$pushService = $this->pushService;
		$threshold = PushNotificationService::REALTIME_BATCH_THRESHOLD;
		$requestRefetch = count($affectedPositions) > $threshold;

		$payload = [
			'documentId' => $documentId,
			'collectionId' => $targetCollectionId,
			'parentId' => $targetParentId,
			'position' => $finalPosition,
			'title' => $title,
			'hasChildren' => $hasChildren,
			'fromCollectionId' => $sourceCollectionId,
			'fromParentId' => $sourceParentId,
		];
		if ($fromParentHasChildren !== null)
		{
			$payload['fromParentHasChildren'] = $fromParentHasChildren;
		}
		if ($requestRefetch)
		{
			$payload['requestRefetch'] = true;
		}
		else
		{
			$payload['affectedPositions'] = $affectedPositions;
		}

		// A move is the one tree event a recipient cannot apply in place: it can add or drop derived
		// rows along the way, so which branch roots are accessible is re-derived server-side. Hence
		// the coarse cascade here, unlike create/rename/removal. Both ends matter — the moved
		// document keeps its own audience inside a granted subtree, and the source parent's audience
		// must learn that a child left it.
		$pushService->notifyDocumentGrantees(
			$targetCollectionId,
			[$documentId, $targetParentId, $sourceParentId],
			$initiatorUserId,
		);
		if ($sourceCollectionId !== null && $sourceCollectionId !== $targetCollectionId)
		{
			$pushService->notifyDocumentGrantees($sourceCollectionId, [$documentId, $sourceParentId], $initiatorUserId);
		}

		$collectionsToNotify = [$targetCollectionId];
		if ($sourceCollectionId !== null && $sourceCollectionId !== $targetCollectionId)
		{
			$collectionsToNotify[] = $sourceCollectionId;
		}

		$pushService->dispatchAfterCommit(static function () use (
			$pushService, $payload, $collectionsToNotify, $documentId, $initiatorUserId,
		): void {
			foreach ($collectionsToNotify as $collectionId)
			{
				$pushService->sendToCollection($collectionId, 'documentMove', $payload, $initiatorUserId);
			}
			$pushService->sendToDocument($documentId, 'documentMove', $payload, $initiatorUserId);
		});
	}

	/**
	 * EVENT-NOTE-01 `moved`: the whole subtree moves as one unit — a single event carrying
	 * every subtree id, with from/to describing only the root's old/new location. The subtree
	 * COLLECTION_ID has already been rewritten to the target by BranchManager, so the live
	 * subtree is enumerated against the target collection.
	 */
	private function emitMoveDomainEvent(
		Document $document,
		?int $sourceCollectionId,
		?int $sourceParentId,
	): void
	{
		if ($sourceCollectionId === null)
		{
			return;
		}

		$documentId = (int)$document->getId();
		$targetCollectionId = (int)$document->getCollectionId();
		$targetParentId = $document->getParentId() !== null ? (int)$document->getParentId() : null;

		// Pure reorder (same COLLECTION_ID and same PARENT_ID — only the sibling position changed) is a no-op
		// for RAG: membership and content are unchanged, so a `moved` event would only trigger a redundant
		// subtree walk + drain churn. Skip the enumeration/emission entirely. A real move (collection or parent
		// changed) still emits the full-subtree event below.
		if ($sourceCollectionId === $targetCollectionId && $sourceParentId === $targetParentId)
		{
			return;
		}

		$subtreeIds = $this->repository->getSubtreeIds($documentId, $targetCollectionId);
		if (empty($subtreeIds))
		{
			$subtreeIds = [$documentId];
		}

		$this->eventPublisher->emitLifecycle(
			OnDocumentLifecycleEvent::MOVED,
			$targetCollectionId,
			$subtreeIds,
			['collectionId' => $sourceCollectionId, 'parentId' => $sourceParentId],
			['collectionId' => $targetCollectionId, 'parentId' => $targetParentId],
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

	private function buildSaveErrorMessage(Result $saveResult, string $defaultMessage): string
	{
		$messages = $saveResult->getErrorMessages();

		return empty($messages) ? $defaultMessage : implode(', ', $messages);
	}

	private function hasEscalationError(Result $result): bool
	{
		foreach ($result->getErrors() as $error)
		{
			if ($error->getCode() === PositionService::ERROR_MOVE_ACCESS_ESCALATION)
			{
				return true;
			}
		}

		return false;
	}
}

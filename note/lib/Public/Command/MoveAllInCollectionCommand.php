<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Access\SubtreeAclReconciler;
use Bitrix\Note\Internal\Service\Bulk\BulkOutcome;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\Document\Position\PositionService;
use Bitrix\Note\Internal\Service\DomainEventPublisher;
use Bitrix\Note\Internal\Service\History\EventLogService;
use Bitrix\Note\Internal\Service\Link\BacklinkLifecycleNotifier;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;

/**
 * "Move the whole knowledge base": every document of one collection goes to another one under a
 * single parent. Set-oriented on purpose — one write for the whole set instead of the node-by-node
 * loop of {@see BulkMoveDocumentsCommand} — and applicable only to this one case: everything is in
 * the set, so nothing is left behind to be orphaned and the archive and the recycle bin travel
 * along. Only the main document stays: it carries the collection's description, not a tree node.
 *
 * The escalation guard runs over the whole set before anything is written, with the threshold of
 * the node-by-node path ({@see PositionService}): a target branch that is shared would otherwise
 * hand its audience a whole knowledge base in one operation.
 *
 * The COLLECTION_ID update, the reparent of the roots and the ACL choke-point are one transaction,
 * because {@see SubtreeAclReconciler::syncOnMoveMany()} demands it — a narrowing DELETE that does
 * not commit with the reparent leaves the former recipients holding access to documents that left.
 * Positions are set after it: a lost position is fixed by the next reorder, a lost narrow by nothing.
 */
class MoveAllInCollectionCommand extends AbstractCommand
{
	private readonly int $collectionId;
	private readonly int $targetCollectionId;
	private readonly ?int $targetParentId;
	private readonly int $userId;
	private readonly DocumentRepository $repository;
	private readonly SubtreeAclReconciler $aclReconciler;
	private readonly PositionService $positionService;
	private readonly PushNotificationService $pushService;
	private readonly EventLogService $eventLogService;
	private readonly DomainEventPublisher $eventPublisher;
	private readonly BacklinkLifecycleNotifier $backlinkNotifier;

	public function __construct(
		int $collectionId,
		int $targetCollectionId,
		?int $targetParentId,
		int $userId,
		?DocumentRepository $repository = null,
		?SubtreeAclReconciler $aclReconciler = null,
		?PositionService $positionService = null,
		?PushNotificationService $pushService = null,
		?EventLogService $eventLogService = null,
		?DomainEventPublisher $eventPublisher = null,
		?BacklinkLifecycleNotifier $backlinkNotifier = null,
	)
	{
		$this->collectionId = $collectionId;
		$this->targetCollectionId = $targetCollectionId;
		$this->targetParentId = $targetParentId !== null && $targetParentId > 0 ? $targetParentId : null;
		$this->userId = $userId;
		$this->repository = $repository ?? new DocumentRepository();
		$this->aclReconciler = $aclReconciler ?? new SubtreeAclReconciler($this->repository);
		$this->positionService = $positionService ?? new PositionService();
		$this->pushService = $pushService ?? new PushNotificationService();
		$this->eventLogService = $eventLogService ?? new EventLogService();
		$this->eventPublisher = $eventPublisher ?? new DomainEventPublisher();
		$this->backlinkNotifier = $backlinkNotifier ?? new BacklinkLifecycleNotifier();
	}

	protected function execute(): Result
	{
		// Carrying the base away is a write into it, so its state decides it: an archived base is
		// read-only, and emptying it wholesale gets no exception to that.
		CollectionAccessService::assertCollectionAcceptsWrites($this->collectionId);

		$result = new Result();

		$documentIds = $this->repository->getCollectionDocumentIds($this->collectionId);
		if (empty($documentIds))
		{
			$result->setData(['outcome' => (new BulkOutcome())->toArray()]);

			return $result;
		}

		if ($this->escalatesAccess($documentIds))
		{
			$result->addError(new Error(
				'Moving here would open the branch to a subtree share; moderator level is required.',
				PositionService::ERROR_MOVE_ACCESS_ESCALATION,
			));

			return $result;
		}

		// The whole tree as it was: the roots to reparent, the subtree of each of them and the old
		// location every `moved` event reports are all read off this one map, before anything is
		// written — afterwards the roots point at the target parent and none of it can be recovered.
		$parentById = $this->repository->getParentMap($this->collectionId, $documentIds);
		$rootIds = $this->rootIdsOf($documentIds, $parentById);
		$liveRootIds = $this->repository->getLiveCollectionRootIds($this->collectionId);
		$subtreeIdsByRootId = $this->subtreesByRoot($rootIds, $documentIds, $parentById);

		$this->applyMove($documentIds, $rootIds, $subtreeIdsByRootId);

		// Outside the transaction, and only over the live roots: a position is a property of the live
		// tree, and an archived root takes no place among live siblings.
		if (!empty($liveRootIds))
		{
			$this->positionService->reorder($this->targetCollectionId, $this->targetParentId, $liveRootIds, $this->userId);
		}

		$this->eventLogService->recordMany(EventTable::SCOPE_DOCUMENT, $rootIds, 'moved', $this->userId);

		try
		{
			$this->emitRefetch();
		}
		catch (\Throwable)
		{
		}

		$this->emitMoveEvents($rootIds, $parentById, $subtreeIdsByRootId);

		try
		{
			$this->backlinkNotifier->sourcesChanged($documentIds);
		}
		catch (\Throwable)
		{
		}

		$result->setData([
			'outcome' => (new BulkOutcome(processedCount: count($documentIds)))->toArray(),
		]);

		return $result;
	}

	/**
	 * @param int[] $documentIds
	 * @param int[] $rootIds
	 * @param array<int, int[]> $subtreeIdsByRootId
	 */
	private function applyMove(array $documentIds, array $rootIds, array $subtreeIdsByRootId): void
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$this->repository->moveByIdsToCollection($documentIds, $this->targetCollectionId);
			$this->repository->updateMulti($rootIds, ['PARENT_ID' => $this->targetParentId]);
			$this->aclReconciler->syncOnMoveMany($subtreeIdsByRootId, $this->targetParentId, $this->targetCollectionId);

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			try
			{
				$connection->rollbackTransaction();
			}
			catch (\Throwable)
			{
			}

			throw $e;
		}
	}

	/**
	 * @param int[] $documentIds
	 * @param array<int, ?int> $parentById
	 * @return int[]
	 */
	private function rootIdsOf(array $documentIds, array $parentById): array
	{
		$inSet = array_fill_keys($documentIds, true);

		$rootIds = [];
		foreach ($documentIds as $id)
		{
			$parentId = $parentById[$id] ?? null;
			// A parent outside the set can only be the main document, which is nobody's parent — but a
			// node whose parent is missing is a root all the same, and it may not be left un-reparented.
			if ($parentId === null || !isset($inSet[$parentId]))
			{
				$rootIds[] = $id;
			}
		}

		return $rootIds;
	}

	/**
	 * Splits the set into the subtree of each root, one walk down the children map.
	 *
	 * @param int[] $rootIds
	 * @param int[] $documentIds
	 * @param array<int, ?int> $parentById
	 * @return array<int, int[]> rootId => the root and its descendants
	 */
	private function subtreesByRoot(array $rootIds, array $documentIds, array $parentById): array
	{
		$childrenByParent = [];
		foreach ($documentIds as $id)
		{
			$parentId = $parentById[$id] ?? null;
			if ($parentId !== null)
			{
				$childrenByParent[$parentId][] = $id;
			}
		}

		$subtreeIdsByRootId = [];
		foreach ($rootIds as $rootId)
		{
			$subtreeIds = [];
			$queue = [$rootId];
			$seen = [];
			// The queue is walked by index: array_shift() would reindex it on every step.
			for ($index = 0; isset($queue[$index]); $index++)
			{
				$currentId = $queue[$index];
				if (isset($seen[$currentId]))
				{
					continue;
				}

				$seen[$currentId] = true;
				$subtreeIds[] = $currentId;
				foreach ($childrenByParent[$currentId] ?? [] as $childId)
				{
					$queue[] = $childId;
				}
			}

			$subtreeIdsByRootId[$rootId] = $subtreeIds;
		}

		return $subtreeIdsByRootId;
	}

	/**
	 * @param int[] $documentIds the whole set that moves
	 */
	private function escalatesAccess(array $documentIds): bool
	{
		if (PortalAdmin::isAdmin($this->userId))
		{
			return false;
		}

		if (!$this->aclReconciler->movementEscalatesAccess($this->targetParentId, $documentIds))
		{
			return false;
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($this->userId);
		$level = CollectionAccessService::getUserLevel($this->targetCollectionId, $this->userId, $accessCodes);

		return $level < CollectionAccessService::LEVEL_MODERATE;
	}

	/**
	 * @param int[] $rootIds
	 * @param array<int, ?int> $parentById
	 * @param array<int, int[]> $subtreeIdsByRootId
	 */
	private function emitMoveEvents(array $rootIds, array $parentById, array $subtreeIdsByRootId): void
	{
		// EVENT-NOTE-01 `moved`, one event per root carrying its whole subtree — the contract
		// MoveDocumentCommand emits for a single move. Best-effort: the move is already applied.
		foreach ($rootIds as $rootId)
		{
			try
			{
				$this->eventPublisher->emitLifecycle(
					OnDocumentLifecycleEvent::MOVED,
					$this->targetCollectionId,
					$subtreeIdsByRootId[$rootId] ?? [$rootId],
					['collectionId' => $this->collectionId, 'parentId' => $parentById[$rootId] ?? null],
					['collectionId' => $this->targetCollectionId, 'parentId' => $this->targetParentId],
					initiatorId: $this->userId,
				);
			}
			catch (\Throwable)
			{
			}
		}
	}

	/**
	 * Both bases, not only the target: the sidebar and the open lists of the source have just lost
	 * everything they showed, and nothing else tells them so.
	 */
	private function emitRefetch(): void
	{
		$collectionIds = array_unique([$this->collectionId, $this->targetCollectionId]);
		$initiatorUserId = $this->userId;
		$pushService = $this->pushService;

		$pushService->dispatchAfterCommit(static function () use ($pushService, $collectionIds, $initiatorUserId): void {
			foreach ($collectionIds as $collectionId)
			{
				$pushService->sendToCollection(
					$collectionId,
					'documentMove',
					['collectionId' => $collectionId, 'requestRefetch' => true],
					$initiatorUserId,
				);
			}
		});
	}
}

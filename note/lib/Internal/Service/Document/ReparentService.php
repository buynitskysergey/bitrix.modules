<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Document;

use Bitrix\Main\Application;
use Bitrix\Note\Infrastructure\Agent\Access\SubtreeAclReconcileScheduler;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Access\SubtreeAclReconciler;
use Bitrix\Note\Internal\Service\Document\Position\PositionService;

/**
 * [FEAT-kb2-tree-bulk-archive / P2.T1 / ALG-02] Re-hangs a node's direct live children onto
 * the node's own parent (its grandparent), or onto the collection root when the node is a
 * root. Applied when a node is archived / trashed WITHOUT its subtree, so its children keep
 * living one level up instead of vanishing with the node.
 *
 * Orchestration lives in this service, not in the repository: the repository must not depend
 * on PositionService. The repository contributes only primitives (listDirectChildren read,
 * updateMulti write); this service sequences them and the follow-up position recompute.
 *
 * [P4.T5] This is also one of the reparent entries that MUST route through the ACL choke-point
 * {@see SubtreeAclReconciler::syncOnMove()} — see the call below for why.
 */
final class ReparentService
{
	private SubtreeAclReconciler $subtreeAclReconciler;

	public function __construct(
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly PositionService $positionService = new PositionService(),
		?SubtreeAclReconciler $subtreeAclReconciler = null,
	)
	{
		$this->subtreeAclReconciler = $subtreeAclReconciler
			?? new SubtreeAclReconciler($this->documentRepository, new SubtreeAclReconcileScheduler());
	}

	/**
	 * Moves the direct live children of $nodeId to $nodeId's parent (grandparent), or to the
	 * collection root when the node has no parent, then normalizes the target branch order.
	 * No-op when the node is gone or has no live children. The collection is never changed.
	 *
	 * Only the direct children move; each child's own subtree travels with it unchanged.
	 */
	public function reparentChildren(int $nodeId, int $collectionId, int $userId): void
	{
		$node = $this->documentRepository->getMetaById($nodeId, ['ID', 'COLLECTION_ID', 'PARENT_ID']);
		if ($node === null)
		{
			return;
		}

		$newParentId = $node->getParentId(); // grandparent; null -> collection root

		$children = $this->documentRepository->listDirectChildren($nodeId, $collectionId);
		if (empty($children))
		{
			return;
		}

		$childIds = array_map(static fn($child): int => (int)$child->getId(), $children);

		// Subtree ids are read BEFORE the re-hang: ids are stable across a reparent, but the parent
		// chain they are derived from is not. One batched walk, not one per child — a node with many
		// children is exactly the case this runs on.
		$subtreesByChild = $this->documentRepository->getSubtreeIdsByRoot($childIds, $collectionId, true);

		// Re-hang (PARENT_ID only) and the ACL sync go together or not at all: syncOnMoveMany's own
		// contract demands it, because a re-hang that commits without its narrowing DELETE leaves the
		// former recipients holding access to documents that left the branch, and nothing later fixes
		// that. reorder() runs its own transaction and branch lock, so it stays outside.
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$this->documentRepository->updateMulti($childIds, ['PARENT_ID' => $newParentId]);

			// [P4.T5] A reparent is a reparent no matter which command triggered it: archiving or
			// trashing $nodeId WITHOUT its subtree lifts these children out of $nodeId's subtree, so
			// every derived row pushed by $nodeId (or by any other source that no longer covers them)
			// has to go.
			$this->subtreeAclReconciler->syncOnMoveMany($subtreesByChild, $newParentId, $collectionId);

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

		$this->positionService->reorder($collectionId, $newParentId, $childIds, $userId);
	}
}

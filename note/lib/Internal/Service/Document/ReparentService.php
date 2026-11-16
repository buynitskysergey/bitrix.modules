<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Document;

use Bitrix\Main\Application;
use Bitrix\Note\Infrastructure\Agent\Access\SubtreeAclReconcileScheduler;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\Access\SubtreeAclReconciler;
use Bitrix\Note\Internal\Service\Document\Position\PositionService;

/**
 * Re-hangs documents onto a new parent. Two entries over one primitive: the direct live children of
 * a node onto that node's own parent — applied when a node is archived / trashed WITHOUT its
 * subtree, so its children keep living one level up instead of vanishing with the node — and an
 * explicitly listed set onto an explicitly named target, which is what the boundary re-hang of a
 * bulk operation asks for.
 *
 * Orchestration lives in this service, not in the repository: the repository must not depend
 * on PositionService. The repository contributes only primitives (listDirectChildren read,
 * updateMulti write); this service sequences them and the follow-up position recompute.
 *
 * This is also one of the reparent entries that MUST route through the ACL choke-point
 * {@see SubtreeAclReconciler::syncOnMove()} — see the call below for why.
 */
final class ReparentService
{
	private SubtreeAclReconciler $subtreeAclReconciler;

	private RecycleBinRepository $recycleBinRepository;

	public function __construct(
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly PositionService $positionService = new PositionService(),
		?SubtreeAclReconciler $subtreeAclReconciler = null,
		?RecycleBinRepository $recycleBinRepository = null,
	)
	{
		$this->subtreeAclReconciler = $subtreeAclReconciler
			?? new SubtreeAclReconciler($this->documentRepository, new SubtreeAclReconcileScheduler());
		$this->recycleBinRepository = $recycleBinRepository ?? new RecycleBinRepository();
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
		$node = $this->documentRepository->getMetaById($nodeId, DocumentRepository::MOVE_META_SELECT);
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

		$this->reparentChildrenTo(
			$childIds,
			$collectionId,
			$userId,
			$newParentId === null ? null : (int)$newParentId,
		);
	}

	/**
	 * Moves exactly the listed documents under $newParentId ($newParentId = null — the collection
	 * root) and normalizes the target branch order. The collection is never changed.
	 *
	 * Only the listed documents move; each one's own subtree travels with it unchanged.
	 *
	 * The list may hold archived documents and documents lying in the recycle bin — that is what the
	 * pull-out before a move hands over. A new PARENT_ID and the ACL sync are theirs just as much as
	 * the live ones', but only the live subset is reordered: a position is a property of the live
	 * tree, and an archived document takes none among live siblings.
	 *
	 * @param int[] $childIds
	 */
	public function reparentChildrenTo(array $childIds, int $collectionId, int $userId, ?int $newParentId): void
	{
		$childIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $childIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($childIds) || $collectionId <= 0)
		{
			return;
		}

		// Subtree ids are read BEFORE the re-hang: ids are stable across a reparent, but the parent
		// chain they are derived from is not. One batched walk, not one per child — a node with many
		// children is exactly the case this runs on.
		$subtreesByChild = $this->documentRepository->getSubtreeIdsByRoot($childIds, $collectionId, true);
		$liveChildIds = $this->liveSubsetOf($childIds);

		// Re-hang (PARENT_ID only) and the ACL sync go together or not at all: syncOnMoveMany's own
		// contract demands it, because a re-hang that commits without its narrowing DELETE leaves the
		// former recipients holding access to documents that left the branch, and nothing later fixes
		// that. reorder() runs its own transaction and branch lock, so it stays outside.
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$this->documentRepository->updateMulti($childIds, ['PARENT_ID' => $newParentId]);

			// A reparent is a reparent no matter which command triggered it: archiving or
			// trashing the former parent WITHOUT its subtree, or the whole branch above these documents
			// leaving the set, lifts them out of that subtree — so every derived row pushed by a source
			// that no longer covers them has to go.
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

		if (!empty($liveChildIds))
		{
			$this->positionService->reorder($collectionId, $newParentId, $liveChildIds, $userId);
		}
	}

	/**
	 * @param int[] $childIds
	 * @return int[] in the order they were given
	 */
	private function liveSubsetOf(array $childIds): array
	{
		$documents = $this->documentRepository->getMetaByIds($childIds, ['ID', 'IS_ARCHIVED']);
		$trashed = $this->recycleBinRepository->filterTrashedDocumentIds($childIds);

		$live = [];
		foreach ($documents as $document)
		{
			$id = (int)$document->getId();
			if (!$document->getIsArchived() && !isset($trashed[$id]))
			{
				$live[] = $id;
			}
		}

		return $live;
	}
}

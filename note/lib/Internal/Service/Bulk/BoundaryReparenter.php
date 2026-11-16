<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Bulk;

use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Document\ReparentService;

/**
 * Keeps the documents outside a bulk set where a reader would still expect to find them, once the
 * set itself is gone from the tree: every child of the set that is not in the set is re-hung onto
 * the nearest ancestor that stays — the collection root when there is none.
 *
 * One pass over the whole boundary instead of one re-hang per node: the group of a target is moved
 * by a single {@see ReparentService::reparentChildrenTo()} call, and a node whose children are all
 * inside the set is not touched at all — its branch leaves whole and its children keep their parent.
 *
 * A move asks for one step more. Its selection travels as a whole, so before the boundary is taken
 * out, the selection is assembled: a selected node with a selected ancestor moves under that
 * ancestor ({@see gatherSelected()}), and only then do the non-selected children leave
 * ({@see rehangOutsiders()}). The order is not interchangeable, and the parent map the second step
 * groups by has to be a snapshot taken before the first one — see rehangOutsiders().
 */
final class BoundaryReparenter
{
	/** The collection root, as a key of the grouping — a parent id no document ever has. */
	public const ROOT = 0;

	private ReparentService $reparentService;

	public function __construct(
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		?ReparentService $reparentService = null,
	)
	{
		$this->reparentService = $reparentService ?? new ReparentService($this->documentRepository);
	}

	/**
	 * Groups the boundary children by the parent they end up under.
	 *
	 * Pure: everything it needs is on the arguments, so the walk is the same whether the nodes are
	 * being archived, deleted or moved away.
	 *
	 * @param array<int, ?int> $parentById documentId => parentId, as the tree was BEFORE the operation
	 * @param int[] $affectedIds the set leaving the tree
	 * @param array<int, int[]> $boundaryChildrenByParent parentId => its children outside the set
	 * @return array<int, int[]> newParentId => child ids; the key {@see ROOT} means the collection root
	 */
	public static function groupByNearestSurvivor(
		array $parentById,
		array $affectedIds,
		array $boundaryChildrenByParent,
	): array
	{
		$affected = [];
		foreach ($affectedIds as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$affected[$id] = true;
			}
		}

		$byNewParent = [];

		// Neighbouring parents of the boundary usually share the tail of their climb, so the answer
		// found for one of them is kept for the whole chain it was found through.
		$survivorByNode = [];

		foreach ($boundaryChildrenByParent as $parentId => $childIds)
		{
			if (empty($childIds))
			{
				continue;
			}

			$survivorId = self::nearestSurvivor((int)$parentId, $parentById, $affected, $survivorByNode);
			foreach ($childIds as $childId)
			{
				$childId = (int)$childId;
				if ($childId > 0)
				{
					$byNewParent[$survivorId][] = $childId;
				}
			}
		}

		return $byNewParent;
	}

	/**
	 * Re-hangs the boundary of $affectedIds. Nothing happens when the set has no children outside it.
	 *
	 * $parentById is read here unless it is given, and a given one wins: the re-hang that follows the
	 * assembly of a move selection needs the map as it was BEFORE that assembly, or the walk would
	 * climb the PARENT_IDs the assembly has just rewritten.
	 *
	 * $liveOnly is passed straight to the boundary read: archiving and deleting leave the node in its
	 * own knowledge base and have no business touching its archive, while a move takes the node away
	 * and every non-selected descendant — archived and trashed ones included — has to stay behind.
	 *
	 * @param int[] $affectedIds
	 * @param array<int, ?int>|null $parentById
	 */
	public function rehang(
		int $collectionId,
		int $userId,
		array $affectedIds,
		?array $parentById = null,
		bool $liveOnly = true,
	): void
	{
		if ($collectionId <= 0 || empty($affectedIds))
		{
			return;
		}

		$parentById ??= $this->documentRepository->getParentMap($collectionId, $affectedIds);
		$boundaryChildrenByParent = $this->documentRepository->getChildrenOutsideSet(
			$collectionId,
			$affectedIds,
			$liveOnly,
		);

		$byNewParent = self::groupByNearestSurvivor($parentById, $affectedIds, $boundaryChildrenByParent);
		foreach ($byNewParent as $newParentId => $childIds)
		{
			$this->reparentService->reparentChildrenTo(
				$childIds,
				$collectionId,
				$userId,
				$newParentId === self::ROOT ? null : $newParentId,
			);
		}
	}

	/**
	 * The nodes of a selection that have no selected ancestor — the ones a move actually travels by,
	 * everything else riding along inside their subtrees.
	 *
	 * Pure, and it reads the ancestor chains rather than a parent map on purpose: on a chain
	 * "selected -> not selected -> selected" the climb by PARENT_ID stops at the node in the middle
	 * and would call the lower node a top one.
	 *
	 * @param int[] $selectedIds
	 * @param array<int, int[]> $ancestorsById documentId => [parent, ..., root], from getAncestorIdsMap()
	 * @return int[] in the order they were given
	 */
	public static function topNodes(array $selectedIds, array $ancestorsById): array
	{
		$selected = self::asSet($selectedIds);

		$topNodes = [];
		foreach (array_keys($selected) as $id)
		{
			if (self::firstSelectedAncestor($ancestorsById[$id] ?? [], $selected) === null)
			{
				$topNodes[] = $id;
			}
		}

		return $topNodes;
	}

	/**
	 * Groups the selection by the nearest selected node above each of its nodes — the assembly
	 * {@see gatherSelected()} applies. Pure, and blind to the same chains for the same reason as
	 * {@see topNodes()}; a top node has no such ancestor and appears nowhere in the answer.
	 *
	 * @param int[] $selectedIds
	 * @param array<int, int[]> $ancestorsById documentId => [parent, ..., root], from getAncestorIdsMap()
	 * @return array<int, int[]> ancestorId => ids of the selected nodes that go under it
	 */
	public static function nearestSelectedAncestor(array $selectedIds, array $ancestorsById): array
	{
		$selected = self::asSet($selectedIds);

		$byAncestor = [];
		foreach (array_keys($selected) as $id)
		{
			$ancestorId = self::firstSelectedAncestor($ancestorsById[$id] ?? [], $selected);
			if ($ancestorId !== null)
			{
				$byAncestor[$ancestorId][] = $id;
			}
		}

		return $byAncestor;
	}

	/**
	 * First step of a move: every selected node that has a selected ancestor moves directly under it,
	 * so that each top node's subtree holds the selection and nothing else. Without it the chain
	 * "selected -> not selected -> selected" breaks apart on the second step — the node in the middle
	 * is lifted out and takes the selected node below it along, because a re-hang rewrites the
	 * PARENT_ID of the listed nodes only and their subtrees follow them.
	 *
	 * A node already hanging under its nearest selected ancestor is left alone, and a group left
	 * empty by that is not dispatched at all. A re-hang is not free of consequence for a node that
	 * does not need it: reparentChildrenTo() reorders the target branch, and the branch is rebuilt
	 * with the listed ids first and in the order they were listed
	 * ({@see \Bitrix\Note\Internal\Service\Document\Position\BranchManager}), so re-hanging the
	 * children of a selected parent onto the parent they already have floats them to the top of the
	 * branch in the order of the ancestor map — a reshuffle the user never asked for.
	 *
	 * $parentById is read here unless it is given; the caller passes the same snapshot it hands to
	 * {@see rehangOutsiders()}, so both steps read one map of the tree as it was.
	 *
	 * @param int[] $selectedIds
	 * @param array<int, ?int>|null $parentById
	 */
	public function gatherSelected(
		array $selectedIds,
		int $collectionId,
		int $userId,
		?array $parentById = null,
	): void
	{
		if ($collectionId <= 0 || empty($selectedIds))
		{
			return;
		}

		$parentById ??= $this->documentRepository->getParentMap($collectionId, $selectedIds);
		$ancestorsById = $this->documentRepository->getAncestorIdsMap($selectedIds);

		foreach (self::nearestSelectedAncestor($selectedIds, $ancestorsById) as $ancestorId => $childIds)
		{
			$movingIds = [];
			foreach ($childIds as $childId)
			{
				if ((int)($parentById[$childId] ?? self::ROOT) !== $ancestorId)
				{
					$movingIds[] = $childId;
				}
			}

			if (!empty($movingIds))
			{
				$this->reparentService->reparentChildrenTo($movingIds, $collectionId, $userId, $ancestorId);
			}
		}
	}

	/**
	 * Second step of a move: the direct children of the selection that are not in it stay in this
	 * knowledge base and go to the nearest node above them that stays. Archived children and children
	 * lying in the recycle bin go with the live ones — they are outside the selection by definition,
	 * as the tab offers no way to select them, and the node they hang under is leaving the base.
	 *
	 * $parentByIdBeforeGather has to be the snapshot taken BEFORE {@see gatherSelected()}: the
	 * assembly has already rewritten the PARENT_IDs of the selection, and on the fresh map the chain
	 * "A(selected) -> M(not) -> D(selected) -> C(not)" would send C to the base root past M, because
	 * the climb from D would find A. In the tree as it was, the nearest node above C that stays is M.
	 *
	 * @param int[] $selectedIds
	 * @param array<int, ?int> $parentByIdBeforeGather
	 */
	public function rehangOutsiders(
		array $selectedIds,
		int $collectionId,
		int $userId,
		array $parentByIdBeforeGather,
	): void
	{
		$this->rehang($collectionId, $userId, $selectedIds, $parentByIdBeforeGather, false);
	}

	/**
	 * @param int[] $ids
	 * @return array<int, true>
	 */
	private static function asSet(array $ids): array
	{
		$set = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$set[$id] = true;
			}
		}

		return $set;
	}

	/**
	 * @param int[] $ancestorIds ordered [parent, ..., root]
	 * @param array<int, true> $selected
	 */
	private static function firstSelectedAncestor(array $ancestorIds, array $selected): ?int
	{
		foreach ($ancestorIds as $ancestorId)
		{
			$ancestorId = (int)$ancestorId;
			if (isset($selected[$ancestorId]))
			{
				return $ancestorId;
			}
		}

		return null;
	}

	/**
	 * The answer is the same for every node of the climbed chain, so all of them are remembered at
	 * once and a later climb stops at the first node already known — including the walk that ends on
	 * a repeated node, which gives {@see ROOT} to that whole chain just the same.
	 *
	 * @param array<int, ?int> $parentById
	 * @param array<int, true> $affected
	 * @param array<int, int> $survivorByNode nodeId => its survivor, carried between the calls of one grouping
	 */
	private static function nearestSurvivor(
		int $nodeId,
		array $parentById,
		array $affected,
		array &$survivorByNode,
	): int
	{
		$current = $nodeId;
		$seen = [];

		while ($current > 0 && isset($affected[$current]))
		{
			if (isset($survivorByNode[$current]))
			{
				return self::rememberSurvivor($seen, $survivorByNode[$current], $survivorByNode);
			}

			// A parent chain is a chain, but a map handed in from outside is only as sound as its
			// source; a repeated node means the walk would never end.
			if (isset($seen[$current]))
			{
				return self::rememberSurvivor($seen, self::ROOT, $survivorByNode);
			}

			$seen[$current] = true;
			$current = (int)($parentById[$current] ?? self::ROOT);
		}

		return self::rememberSurvivor($seen, max($current, self::ROOT), $survivorByNode);
	}

	/**
	 * @param array<int, true> $chain the nodes climbed through
	 * @param array<int, int> $survivorByNode
	 */
	private static function rememberSurvivor(array $chain, int $survivorId, array &$survivorByNode): int
	{
		foreach (array_keys($chain) as $nodeId)
		{
			$survivorByNode[$nodeId] = $survivorId;
		}

		return $survivorId;
	}
}

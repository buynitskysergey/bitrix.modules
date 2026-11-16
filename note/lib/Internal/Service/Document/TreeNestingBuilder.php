<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Document;

/**
 * Nesting of a flat map of nodes into trees, and truncation of the result by a node budget.
 *
 * The class stands under both circuits - the ordinary tree of an employee and the tree of a public
 * link - and that is the only thing they share: the field set of a node is decided by the caller.
 * Of a node this class reads `id`, `parentId` and `children` and nothing else, so a caller is free
 * to project its own white list into it.
 *
 * Nothing here asks about repositories, rights or the current user: the input is nodes, the output
 * is nodes.
 */
final class TreeNestingBuilder
{
	/**
	 * @param array<int, array> $nodes
	 * @return array<int, int[]> child ids keyed by parent id
	 */
	public function buildChildrenMap(array $nodes): array
	{
		$childrenByParent = [];
		foreach ($nodes as $id => $node)
		{
			$parentId = $node['parentId'];
			if ($parentId !== null && isset($nodes[$parentId]))
			{
				$childrenByParent[$parentId][] = $id;
			}
		}

		return $childrenByParent;
	}

	/**
	 * @param array<int, array> $nodes
	 * @param int[] $rootIds
	 * @return array[] roots with their children nested inside
	 */
	public function nestNodes(array $nodes, array $rootIds): array
	{
		foreach ($nodes as $id => &$node)
		{
			$parentId = $node['parentId'];
			if ($parentId !== null && isset($nodes[$parentId]))
			{
				$nodes[$parentId]['children'][] = &$node;
			}
		}
		unset($node);

		$items = [];
		foreach ($rootIds as $rootId)
		{
			if (isset($nodes[$rootId]))
			{
				$items[] = $nodes[$rootId];
			}
		}

		return $items;
	}

	/**
	 * Keeps whole roots while they fit the budget, and cuts inside the first root only when even it
	 * alone does not fit: a tree of a single huge root would otherwise come back empty.
	 *
	 * @param array<int, array> $nodes
	 * @param int[] $rootIds
	 * @return array{0: array<int, array>, 1: int[], 2: bool} kept nodes, kept roots, truncated flag
	 */
	public function truncateByRoots(array $nodes, array $rootIds, int $maxNodes): array
	{
		$childrenByParent = $this->buildChildrenMap($nodes);

		$keptNodes = [];
		$keptRoots = [];
		foreach ($rootIds as $rootId)
		{
			$remaining = $maxNodes - count($keptNodes);
			if ($remaining <= 0)
			{
				break;
			}

			$subtree = $this->collectSubtreeBfs($rootId, $childrenByParent, $nodes, $remaining);

			if (count($subtree) > $remaining)
			{
				if (empty($keptRoots))
				{
					$subtree = array_slice($subtree, 0, $remaining, true);
				}
				else
				{
					break;
				}
			}

			$keptRoots[] = $rootId;
			foreach ($subtree as $id => $node)
			{
				$keptNodes[$id] = $node;
			}
		}

		return [$keptNodes, $keptRoots, true];
	}

	/**
	 * @param array<int, int[]> $childrenByParent
	 * @param array<int, array> $nodes
	 * @return array<int, array>
	 */
	private function collectSubtreeBfs(int $rootId, array $childrenByParent, array $nodes, int $softLimit): array
	{
		$subtree = [];
		$queue = [$rootId];
		// The queue is walked with a moving head instead of array_shift, which reindexes the whole
		// queue on every step. On a branch that is wide rather than deep - one root with tens of
		// thousands of children, the shape an import leaves behind - that reindexing is what the walk
		// spends its time on, and it grows with the square of the branch.
		for ($head = 0; $head < count($queue); $head++)
		{
			$current = $queue[$head];
			$subtree[$current] = $nodes[$current];
			if (count($subtree) > $softLimit)
			{
				return $subtree;
			}
			if (isset($childrenByParent[$current]))
			{
				foreach ($childrenByParent[$current] as $childId)
				{
					$queue[] = $childId;
				}
			}
		}

		return $subtree;
	}
}

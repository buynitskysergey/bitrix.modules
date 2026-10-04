<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Layout;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlockCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnectionCollection;
use SplQueue;

/**
 * Deterministic layered-tree layout of the agent graph. The graph is a tree of flows that branch on
 * condition blocks (with DAG merges where branches rejoin): depth grows left-to-right along a flow, and each
 * branch reserves its own vertical band sized by its subtree, so children never overlap however deeply the
 * branching nests.
 *
 * Row assignment is a classic tidy-tree reservation over a spanning tree of the component: a leaf takes the
 * next free row, an internal node is centred between its first and last child. DAG merge nodes (more than one
 * predecessor) are placed after all their predecessors and attached to the last one, mirroring the previous
 * engine's merge handling. Same input -> same output (no time/random).
 */
final class TopologyLayoutEngine
{
	private const START_X = 100;
	private const START_Y = 200;

	// Horizontal gap between consecutive nodes along a flow ("a bit more"). Calibrated on live template 406.
	private const X_STEP = 500;

	// Vertical gap between sibling branches, sized at 2 x {@see FrameGeometry::MIN_HEIGHT} so a branch row fits
	// two adjacent minimum-height (380px) frames without collision - including a branching parent that sits half
	// a row above its lower child and still lands inside a frame. Calibrated on live template 406.
	private const BRANCH_ROW_OFFSET = 760;

	// Vertical gap inserted between disconnected flows (components), with the same 2 x {@see
	// FrameGeometry::MIN_HEIGHT} band so frames on adjacent flows never collide. Calibrated on live template 406.
	private const Y_CHAIN_STEP = 1200;
	private const Y_CHAIN_PADDING = 760;

	/**
	 * @return array<string, array{x: int, y: int}>
	 */
	public function compute(
		AgentBlockCollection $blocks,
		AgentConnectionCollection $connections,
	): array
	{
		if (count($blocks) === 0)
		{
			return [];
		}

		[$blockIds, $successors, $predecessors] = $this->buildGraph($blocks, $connections);

		// Drop structural back-edges so any cyclic flow (ForEach/While/arbitrary loop) reduces to the DAG the
		// reservation layout below expects; an acyclic graph has none, so its layout stays bit-identical.
		foreach ($this->detectBackEdges($blockIds, $successors) as [$src, $dst])
		{
			$successors[$src] = $this->removeFirstOccurrence($successors[$src], $dst);
			$predecessors[$dst] = $this->removeFirstOccurrence($predecessors[$dst], $src);
		}

		$roots = array_values(array_filter($blockIds, static fn(string $id) => empty($predecessors[$id])));
		if (empty($roots))
		{
			$roots = [$blockIds[0]];
		}

		$positions = [];
		$visited = [];
		$componentBaseY = self::START_Y;
		$maxY = self::START_Y;

		foreach ($roots as $root)
		{
			if (isset($visited[$root]))
			{
				continue;
			}

			$componentPositions = $this->layoutComponent(
				$root,
				$successors,
				$predecessors,
				$componentBaseY,
				$visited,
			);

			foreach ($componentPositions as $id => $pos)
			{
				$positions[$id] = $pos;
				if ($pos['y'] > $maxY)
				{
					$maxY = $pos['y'];
				}
			}

			$componentBaseY = max(
				$componentBaseY + self::Y_CHAIN_STEP,
				$maxY + self::Y_CHAIN_PADDING,
			);
		}

		foreach ($blockIds as $id)
		{
			if (isset($visited[$id]))
			{
				continue;
			}

			$positions[$id] = ['x' => self::START_X, 'y' => $componentBaseY];
			$visited[$id] = true;
			$maxY = max($maxY, $componentBaseY);
			$componentBaseY = max(
				$componentBaseY + self::Y_CHAIN_STEP,
				$maxY + self::Y_CHAIN_PADDING,
			);
		}

		return $positions;
	}

	/**
	 * @return array{0: list<string>, 1: array<string, list<string>>, 2: array<string, list<string>>}
	 */
	private function buildGraph(
		AgentBlockCollection $blocks,
		AgentConnectionCollection $connections,
	): array
	{
		$blockIds = [];
		$successors = [];
		$predecessors = [];

		foreach ($blocks as $block)
		{
			$blockIds[] = $block->id;
			$successors[$block->id] = [];
			$predecessors[$block->id] = [];
		}

		foreach ($connections as $connection)
		{
			$src = $connection->sourceBlockId;
			$dst = $connection->destinationBlockId;

			if (!array_key_exists($src, $successors) || !array_key_exists($dst, $predecessors))
			{
				continue;
			}

			$successors[$src][] = $dst;
			$predecessors[$dst][] = $src;
		}

		return [$blockIds, $successors, $predecessors];
	}

	/**
	 * Structural back-edge detection over the raw graph via an iterative DFS: an edge u -> v is a back-edge iff
	 * v is grey (still on the current DFS recursion stack). Dropping exactly these edges turns any cyclic graph -
	 * a ForEach loop (loop-back into the iterator port), a While loop (closing through the condition) or an
	 * arbitrary cycle - into a DAG, while an acyclic graph yields none. Detection is structural (by the DFS
	 * stack), not by port name, so it is agnostic to the ports the engine already ignores. It starts from every
	 * node in {@see buildGraph} order and follows successors in their stored order, so the detected set - and
	 * therefore the layout - is fully deterministic.
	 *
	 * @param list<string> $blockIds
	 * @param array<string, list<string>> $successors
	 * @return list<array{0: string, 1: string}> back-edges as [source, destination] pairs
	 */
	private function detectBackEdges(array $blockIds, array $successors): array
	{
		$grey = 1;
		$black = 2;
		$color = [];
		$backEdges = [];

		foreach ($blockIds as $startId)
		{
			if (isset($color[$startId]))
			{
				continue;
			}

			// Explicit stack of [nodeId, nextChildIndex] frames - an iterative DFS to avoid deep recursion.
			$stack = [[$startId, 0]];
			$color[$startId] = $grey;

			while (!empty($stack))
			{
				$top = array_key_last($stack);
				[$nodeId, $childIndex] = $stack[$top];

				if ($childIndex >= count($successors[$nodeId]))
				{
					$color[$nodeId] = $black;
					array_pop($stack);
					continue;
				}

				$stack[$top][1] = $childIndex + 1;
				$childId = $successors[$nodeId][$childIndex];

				if (($color[$childId] ?? 0) === $grey)
				{
					$backEdges[] = [$nodeId, $childId];
				}
				elseif (!isset($color[$childId]))
				{
					$color[$childId] = $grey;
					$stack[] = [$childId, 0];
				}
			}
		}

		return $backEdges;
	}

	/**
	 * @param list<string> $list
	 * @return list<string>
	 */
	private function removeFirstOccurrence(array $list, string $value): array
	{
		$index = array_search($value, $list, true);
		if ($index !== false)
		{
			array_splice($list, $index, 1);
		}

		return $list;
	}

	/**
	 * Lays out one weakly-connected component reachable from $root. Phase 1 walks the component in BFS order,
	 * assigning each node its depth (x-layer) and building the spanning tree (primary-parent -> children) while
	 * honouring DAG merges. Phase 2 assigns rows by subtree reservation over that tree.
	 *
	 * @param array<string, list<string>> $successors
	 * @param array<string, list<string>> $predecessors
	 * @param array<string, true> $visited
	 * @return array<string, array{x: int, y: int}>
	 */
	private function layoutComponent(
		string $root,
		array $successors,
		array $predecessors,
		int $baseY,
		array &$visited,
	): array
	{
		$reachable = $this->findReachable($root, $successors, $visited);
		$pendingCount = $this->buildPendingCount($reachable, $predecessors);

		$depth = [];
		$treeChildren = [];
		$componentNodes = [];

		/** @var SplQueue<array{0: string, 1: int, 2: string|null}> $queue [id, depth, primaryParent] */
		$queue = new SplQueue();
		$queue->enqueue([$root, 0, null]);

		while (!$queue->isEmpty())
		{
			[$blockId, $blockDepth, $parentId] = $queue->dequeue();

			if (isset($visited[$blockId]))
			{
				continue;
			}

			$visited[$blockId] = true;
			$depth[$blockId] = $blockDepth;
			$componentNodes[] = $blockId;
			$treeChildren[$blockId] ??= [];
			if ($parentId !== null)
			{
				$treeChildren[$parentId][] = $blockId;
			}

			foreach ($successors[$blockId] as $childId)
			{
				if (isset($visited[$childId]) || !isset($reachable[$childId]))
				{
					continue;
				}

				$pendingCount[$childId]--;

				if ($pendingCount[$childId] !== 0)
				{
					continue;
				}

				$childDepth = count($predecessors[$childId]) > 1
					? $this->resolveMergeDepth($predecessors[$childId], $depth)
					: $blockDepth + 1;

				$queue->enqueue([$childId, $childDepth, $blockId]);
			}
		}

		$rows = [];
		$cursor = 0;
		$this->assignRows($root, $treeChildren, $rows, $cursor);

		$positions = [];
		foreach ($componentNodes as $id)
		{
			$positions[$id] = [
				'x' => self::START_X + $depth[$id] * self::X_STEP,
				'y' => $baseY + (int)round(($rows[$id] ?? 0.0) * self::BRANCH_ROW_OFFSET),
			];
		}

		return $positions;
	}

	/**
	 * Subtree row reservation over the spanning tree: a leaf takes the next free row, an internal node is
	 * centred between its first and last child. Rows may be fractional (a centred parent), which the caller
	 * rounds to a pixel Y. Deterministic - it follows the tree in the order children were opened.
	 *
	 * @param array<string, list<string>> $treeChildren
	 * @param array<string, float> $rows
	 */
	private function assignRows(string $id, array $treeChildren, array &$rows, int &$cursor): void
	{
		// Explicit stack of [nodeId, nextChildIndex] frames - an iterative post-order walk to avoid deep recursion.
		$stack = [[$id, 0]];

		while (!empty($stack))
		{
			$top = array_key_last($stack);
			[$nodeId, $childIndex] = $stack[$top];
			$children = $treeChildren[$nodeId] ?? [];

			if ($children === [])
			{
				$rows[$nodeId] = (float)$cursor;
				$cursor++;
				array_pop($stack);

				continue;
			}

			if ($childIndex < count($children))
			{
				$stack[$top][1] = $childIndex + 1;
				$stack[] = [$children[$childIndex], 0];

				continue;
			}

			$first = $rows[$children[0]];
			$last = $rows[$children[count($children) - 1]];
			$rows[$nodeId] = ($first + $last) / 2.0;
			array_pop($stack);
		}
	}

	/**
	 * @param array<string, list<string>> $successors
	 * @param array<string, true> $visited
	 * @return array<string, true>
	 */
	private function findReachable(string $root, array $successors, array $visited): array
	{
		$reachable = [];

		/** @var SplQueue<string> $queue */
		$queue = new SplQueue();
		$queue->enqueue($root);

		while (!$queue->isEmpty())
		{
			$current = $queue->dequeue();

			if (isset($reachable[$current]))
			{
				continue;
			}

			$reachable[$current] = true;

			foreach ($successors[$current] as $child)
			{
				if (!isset($reachable[$child]) && !isset($visited[$child]))
				{
					$queue->enqueue($child);
				}
			}
		}

		return $reachable;
	}

	/**
	 * @param array<string, true> $reachable
	 * @param array<string, list<string>> $predecessors
	 * @return array<string, int>
	 */
	private function buildPendingCount(array $reachable, array $predecessors): array
	{
		$pendingCount = [];

		foreach (array_keys($reachable) as $id)
		{
			$count = 0;
			foreach ($predecessors[$id] as $pred)
			{
				if (isset($reachable[$pred]))
				{
					$count++;
				}
			}
			$pendingCount[$id] = $count;
		}

		return $pendingCount;
	}

	/**
	 * A merge node sits one layer past its deepest already-placed predecessor, so it never overlaps any of the
	 * branches feeding into it.
	 *
	 * @param list<string> $nodePredecessors
	 * @param array<string, int> $depth
	 */
	private function resolveMergeDepth(array $nodePredecessors, array $depth): int
	{
		$maxDepth = 0;
		foreach ($nodePredecessors as $pred)
		{
			if (isset($depth[$pred]) && $depth[$pred] > $maxDepth)
			{
				$maxDepth = $depth[$pred];
			}
		}

		return $maxDepth + 1;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Bulk;

/**
 * [FEAT-kb2-tree-bulk-archive / P3.T1 / ALG-03] Topologically orders a bulk restore
 * selection so an ancestor within the selection is applied before its descendant.
 *
 * This is the single guarantee bulk restore adds over single restore: when a parent and
 * one of its descendants are both selected, restoring the parent first means the descendant
 * finds an already-restored live parent and re-attaches to it, instead of falling to the
 * collection root. Unselected ancestors are never built up — only the selected set is
 * ordered. Overlapping subtrees are already deduplicated upstream (SelectionResolver).
 *
 * The order key is the depth within the selection (number of selected ancestors above a
 * node). Nodes whose parent is not in the selection are treated as roots (depth 0). The
 * sort is stable (PHP 8.0+ usort), so nodes of equal depth keep their input order.
 */
final class RestoreOrderer
{
	/**
	 * @param int[] $selectedIds document ids chosen for restore
	 * @param array<int, array{parentId: int|null}> $metaById selected id => its parent id
	 *        (parentId may point outside the selection, or be null for a real root)
	 *
	 * @return int[] the same ids reordered ancestors-first (shallowest depth first)
	 */
	public function order(array $selectedIds, array $metaById): array
	{
		$selected = [];
		foreach ($selectedIds as $id)
		{
			$selected[(int)$id] = true;
		}

		$depthCache = [];
		$depthOf = static function (int $id) use (&$depthCache, $selected, $metaById): int {
			if (isset($depthCache[$id]))
			{
				return $depthCache[$id];
			}

			$depth = 0;
			$guard = [];                       // cycle guard: defensive against malformed parent chains
			$parentId = $metaById[$id]['parentId'] ?? null;
			while ($parentId !== null && isset($selected[$parentId]) && !isset($guard[$parentId]))
			{
				$guard[$parentId] = true;
				$depth++;
				$parentId = $metaById[$parentId]['parentId'] ?? null;
			}

			return $depthCache[$id] = $depth;
		};

		$ordered = array_values(array_map('intval', $selectedIds));
		usort($ordered, static fn(int $a, int $b): int => $depthOf($a) <=> $depthOf($b));

		return $ordered;
	}
}

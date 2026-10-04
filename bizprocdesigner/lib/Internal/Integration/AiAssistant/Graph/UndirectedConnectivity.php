<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Graph;

/**
 * Undirected connectivity over the agent connection list, shared by the forward frame-membership validator
 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentFrameMembershipValidator}) and
 * the reverse converter's frame-member projection
 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantWorkflowTemplateConverterService}).
 *
 * A single traversal implementation guarantees the reverse projection filters members by exactly the same
 * connectivity criterion the forward validator enforces, so a `template.get` -> re-send round-trip stays
 * idempotent: the reverse output can never surface a member set the forward path would reject as disconnected.
 */
final class UndirectedConnectivity
{
	/**
	 * Undirected adjacency keyed by block id, built from the agent connection list. Each connection is a
	 * `{sourceBlockId, destinationBlockId}` shape; both endpoints must be non-empty strings. Malformed
	 * entries are skipped. This is the exact edge model the frame-membership contract is defined over.
	 *
	 * @return array<string, list<string>>
	 */
	public static function buildAdjacency(mixed $connections): array
	{
		$adjacency = [];
		if (!is_array($connections))
		{
			return $adjacency;
		}

		foreach ($connections as $connection)
		{
			if (!is_array($connection))
			{
				continue;
			}

			$source = $connection['sourceBlockId'] ?? null;
			$destination = $connection['destinationBlockId'] ?? null;
			if (!is_string($source) || $source === '' || !is_string($destination) || $destination === '')
			{
				continue;
			}

			$adjacency[$source][] = $destination;
			$adjacency[$destination][] = $source;
		}

		return $adjacency;
	}

	/**
	 * Whether the members form a single connected component over the induced undirected sub-graph (an edge to
	 * a non-member does not connect two members). Trivially true for 0 or 1 members.
	 *
	 * @param list<string> $members
	 * @param array<string, list<string>> $adjacency
	 */
	public static function isConnected(array $members, array $adjacency): bool
	{
		return count(self::largestConnectedComponent($members, $adjacency)) === count($members);
	}

	/**
	 * The largest connected component of the members over the induced undirected sub-graph, preserving the
	 * input order of the surviving members. A fully connected member set is returned unchanged (no-op). Ties
	 * on size are broken deterministically by the lexicographically smallest member id in the component, so
	 * the projection is stable across runs regardless of input ordering.
	 *
	 * @param list<string> $members
	 * @param array<string, list<string>> $adjacency
	 * @return list<string>
	 */
	public static function largestConnectedComponent(array $members, array $adjacency): array
	{
		if (count($members) <= 1)
		{
			return array_values($members);
		}

		$memberSet = array_fill_keys($members, true);
		$componentOf = [];
		$componentSize = [];
		$componentMinId = [];
		$nextComponent = 0;

		foreach ($members as $member)
		{
			if (isset($componentOf[$member]))
			{
				continue;
			}

			$component = $nextComponent++;
			$stack = [$member];
			while ($stack !== [])
			{
				$node = array_pop($stack);
				if (isset($componentOf[$node]))
				{
					continue;
				}

				$componentOf[$node] = $component;
				$componentSize[$component] = ($componentSize[$component] ?? 0) + 1;
				if (!isset($componentMinId[$component]) || strcmp($node, $componentMinId[$component]) < 0)
				{
					$componentMinId[$component] = $node;
				}

				foreach ($adjacency[$node] ?? [] as $neighbour)
				{
					if (isset($memberSet[$neighbour]) && !isset($componentOf[$neighbour]))
					{
						$stack[] = $neighbour;
					}
				}
			}
		}

		$bestComponent = null;
		foreach ($componentSize as $component => $size)
		{
			if (
				$bestComponent === null
				|| $size > $componentSize[$bestComponent]
				|| (
					$size === $componentSize[$bestComponent]
					&& strcmp($componentMinId[$component], $componentMinId[$bestComponent]) < 0
				)
			)
			{
				$bestComponent = $component;
			}
		}

		return array_values(
			array_filter($members, static fn(string $member): bool => $componentOf[$member] === $bestComponent),
		);
	}
}

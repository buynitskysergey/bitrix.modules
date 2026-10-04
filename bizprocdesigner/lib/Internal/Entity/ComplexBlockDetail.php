<?php

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

/**
 * Complex-node detail for the AI block catalog (DTO-02): the dictionary of allowed sub-actions,
 * the fixed document type and whether the node filter is supported in the current environment.
 *
 * Emitted additively by {@see BlockTypeDetail::toArray()} under the `complexActions` key; the whole
 * block is `null` for non-complex blocks (simple/trigger/operators), so the catalog JSON is unchanged
 * for them. The detail is derived from the same source as the manual editor
 * ({@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService}), not a parallel AI description.
 */
class ComplexBlockDetail implements Arrayable
{
	/**
	 * @param list<ComplexNodeAction> $nodeActions allowed sub-actions (empty list for `switchnode`)
	 * @param array|null $fixedDocumentType fixed document type; `null` when the node has no FixedDocumentComplexActivity
	 * @param bool $filterSupported node-filter availability, mirroring `availableBlocks.filter.available`
	 *        of the node: its block descriptor decides, and only a node without one falls back to the
	 *        runtime gate (rollout flag plus a filter-result resolver for the effective document module)
	 */
	public function __construct(
		public readonly array $nodeActions,
		public readonly ?array $fixedDocumentType,
		public readonly bool $filterSupported,
	) {}

	public function toArray(): array
	{
		return [
			'nodeActions' => array_map(
				static fn(ComplexNodeAction $action): array => $action->toArray(),
				$this->nodeActions,
			),
			'fixedDocumentType' => $this->fixedDocumentType,
			'filterSupported' => $this->filterSupported,
		];
	}
}

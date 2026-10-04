<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto\Complex;

use Bitrix\Main\Type\Contract\Arrayable;

final class NodeCapabilityCatalog implements Arrayable, \JsonSerializable
{
	/**
	 * @param string $activityCode
	 * @param string $nodeType
	 * @param BlockAvailability $availableBlocks
	 * @param list<NodeActionCatalogEntry> $actions Flat list of typed action entries.
	 * @param array|null $meta Reserved for rights/plan/region/module constraints. null in MVP.
	 */
	public function __construct(
		public readonly string $activityCode,
		public readonly string $nodeType,
		public readonly BlockAvailability $availableBlocks,
		public readonly array $actions,
		public readonly ?array $meta = null,
	) {}

	public function toArray(): array
	{
		return [
			'activityCode' => $this->activityCode,
			'nodeType' => $this->nodeType,
			'availableBlocks' => $this->availableBlocks->toArray(),
			'actions' => array_map(
				static fn(NodeActionCatalogEntry $entry) => $entry->toArray(),
				$this->actions,
			),
			'meta' => $this->meta,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}

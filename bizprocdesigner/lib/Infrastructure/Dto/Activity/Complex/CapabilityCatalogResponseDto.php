<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex;

use Bitrix\Bizproc\Activity\Dto\Complex\NodeActionCatalogEntry;
use Bitrix\Bizproc\Activity\Dto\Complex\NodeCapabilityCatalog;
use JsonSerializable;

/**
 * Transport DTO for the getCapabilityCatalog endpoint.
 */
class CapabilityCatalogResponseDto implements JsonSerializable
{
	/**
	 * @param string $activityCode
	 * @param string $nodeType
	 * @param AvailableBlocksDto $availableBlocks
	 * @param list<NodeActionCatalogEntry> $actions Flat action list.
	 * @param array|null $meta Reserved for constraints (rights/plan/region/module). null in MVP.
	 */
	public function __construct(
		public readonly string $activityCode,
		public readonly string $nodeType,
		public readonly AvailableBlocksDto $availableBlocks,
		public readonly array $actions,
		public readonly ?array $meta = null,
	) {}

	public static function fromNodeCapabilityCatalog(NodeCapabilityCatalog $catalog): self
	{
		return new self(
			activityCode: $catalog->activityCode,
			nodeType: $catalog->nodeType,
			availableBlocks: AvailableBlocksDto::fromBlockAvailability($catalog->availableBlocks),
			actions: $catalog->actions,
			meta: $catalog->meta,
		);
	}

	public function jsonSerialize(): array
	{
		return [
			'activityCode' => $this->activityCode,
			'nodeType' => $this->nodeType,
			'availableBlocks' => $this->availableBlocks,
			'actions' => array_map(
				static fn(NodeActionCatalogEntry $entry) => $entry->jsonSerialize(),
				$this->actions,
			),
			'meta' => $this->meta,
		];
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex;

use Bitrix\Bizproc\Activity\Dto\Complex\BlockAvailability;
use JsonSerializable;

/**
 * Transport DTO for the available blocks descriptor.
 * Wraps the domain BlockAvailability for JSON serialization in loadSettings and getCapabilityCatalog responses.
 */
class AvailableBlocksDto implements JsonSerializable
{
	public function __construct(
		private readonly BlockAvailability $blockAvailability,
	) {}

	public static function fromBlockAvailability(BlockAvailability $blockAvailability): self
	{
		return new self($blockAvailability);
	}

	public function jsonSerialize(): array
	{
		return $this->blockAvailability->toArray();
	}
}

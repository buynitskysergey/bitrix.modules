<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Canvas;

final class CanvasLink
{
	public function __construct(
		public readonly PortRef $source,
		public readonly PortRef $target,
	) {}

	public static function createBetween(string $sourceNodeId, string $targetNodeId): self
	{
		return new self(
			PortRef::createFromNodeId($sourceNodeId, 'o0'),
			PortRef::createFromNodeId($targetNodeId, 'i0'),
		);
	}

	public function convertToArray(): array
	{
		return [
			$this->source->convertToString(),
			$this->target->convertToString(),
		];
	}
}

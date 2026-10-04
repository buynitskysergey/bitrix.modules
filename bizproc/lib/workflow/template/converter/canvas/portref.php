<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Canvas;

final class PortRef
{
	public function __construct(
		public readonly string $nodeId,
		public readonly string $portId,
	) {}

	public static function createFromOutputNode(string $nodeId, string $portId = 'o0'): self
	{
		return new self($nodeId, $portId);
	}

	public static function createFromInputNode(string $nodeId, string $portId = 'i0'): self
	{
		return new self($nodeId, $portId);
	}

	public static function createFromNodeId(string $nodeId, string $defaultPortId): self
	{
		if (str_contains($nodeId, ':'))
		{
			[$nodeId, $portId] = explode(':', $nodeId, 2);

			return new self($nodeId, $portId);
		}

		return new self($nodeId, $defaultPortId);
	}

	public function convertToString(): string
	{
		return "{$this->nodeId}:{$this->portId}";
	}
}

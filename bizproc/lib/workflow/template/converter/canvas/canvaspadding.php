<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Canvas;

final class CanvasPadding
{
	public function __construct(
		public readonly float $top,
		public readonly float $right,
		public readonly float $bottom,
		public readonly float $left,
	) {}

	public static function createUniform(float $padding): self
	{
		return new self($padding, $padding, $padding, $padding);
	}
}

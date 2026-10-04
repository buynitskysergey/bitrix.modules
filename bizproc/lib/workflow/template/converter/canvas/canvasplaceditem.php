<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Canvas;

final class CanvasPlacedItem
{
	public function __construct(
		public readonly CanvasNode|CanvasContainer $item,
		public readonly float $row,
		public readonly float $column,
		public readonly CanvasSize $size,
	) {}
}

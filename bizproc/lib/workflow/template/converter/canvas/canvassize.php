<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Canvas;

final class CanvasSize
{
	public function __construct(
		public readonly float $rows,
		public readonly float $columns,
	) {}
}

<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Layout;

final class GridPoint
{
	public function __construct(
		public float $row,
		public float $column,
	) {}

	public function moveBy(float $rowShift, float $columnShift): self
	{
		return new self($this->row + $rowShift, $this->column + $columnShift);
	}
}

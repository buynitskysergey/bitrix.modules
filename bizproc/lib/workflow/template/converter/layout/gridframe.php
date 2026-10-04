<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Layout;

final class GridFrame
{
	public function __construct(
		public float $top,
		public float $left,
		public float $bottom,
		public float $right,
	) {}

	public static function createFromPoint(GridPoint $point): self
	{
		return new self($point->row, $point->column, $point->row, $point->column);
	}

	public static function merge(self ...$frames): self
	{
		if (empty($frames))
		{
			return new self(0, 0, 0, 0);
		}

		return new self(
			min(array_map(static fn(self $frame) => $frame->top, $frames)),
			min(array_map(static fn(self $frame) => $frame->left, $frames)),
			max(array_map(static fn(self $frame) => $frame->bottom, $frames)),
			max(array_map(static fn(self $frame) => $frame->right, $frames)),
		);
	}

	public function moveBy(float $rowShift, float $columnShift): self
	{
		return new self(
			$this->top + $rowShift,
			$this->left + $columnShift,
			$this->bottom + $rowShift,
			$this->right + $columnShift,
		);
	}

	public function expand(float $top, float $right, float $bottom, float $left): self
	{
		return new self(
			$this->top - $top,
			$this->left - $left,
			$this->bottom + $bottom,
			$this->right + $right,
		);
	}

	public function calculateWidth(): float
	{
		return $this->right - $this->left + 1;
	}

	public function calculateHeight(): float
	{
		return $this->bottom - $this->top + 1;
	}

	public function getRightBottomPoint(): GridPoint
	{
		return new GridPoint($this->bottom, $this->right);
	}
}

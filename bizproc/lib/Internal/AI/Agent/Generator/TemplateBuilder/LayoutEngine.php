<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder;

/**
 * Lays the nodes of a template out on the canvas: a step moves the chain one column to the right, a branch
 * and a bound node are drawn in a row below it.
 *
 * A row is placed below the nodes that stand in the columns it is going to use - not below the lowest node
 * of the flow. Both are what the nodes take on the canvas, so noteNode() is told about every node the build
 * puts into the template: a node taller than a row would otherwise be covered by the row drawn under it,
 * and a node far to the left would push a branch down for no reason.
 */
final class LayoutEngine
{
	private const X_STEP = 400;
	private const Y_FLOW_STEP = 500;
	private const BRANCH_ROW_OFFSET = 200;
	private const COMPOSITE_LOOPBACK_RESERVE = 200;
	private const START_X = 100;
	private const START_Y = 200;
	/** Rows stand on a grid of this step, so that a row below an ordinary node keeps the offset it always had. */
	private const ROW_GRID = 100;
	/**
	 * Space left free under the node a row is drawn below, before the row is put on the grid. Small enough that
	 * every node no taller than a row keeps the offset the layout always gave it - the tallest node the designer
	 * makes without a body of its own is 135 high - so only a node taller than a row moves the row under it.
	 */
	private const ROW_CLEARANCE = 60;
	/** What a node whose descriptor states no dimensions takes on the canvas - ActivityRegistry says the same. */
	private const NODE_WIDTH = 200;
	private const NODE_HEIGHT = 48;

	private int $currentFlowBaseY = self::START_Y;
	private ?int $yOverride = null;
	/** @var list<int|null> */
	private array $yStack = [];
	/**
	 * Space the nodes of the current flow take, an entry per node: the column its right edge reaches and its
	 * lower edge. A row is drawn below the entries reaching into its columns, so where a node starts does not
	 * matter - only how far to the right it reaches and how low it goes.
	 *
	 * @var list<array{to: int, bottom: int}>
	 */
	private array $occupied = [];

	public function nextFlow(): void
	{
		$lowest = $this->lowestEdgeFrom(self::START_X);
		$nextBaseY = max(
			$this->currentFlowBaseY + self::Y_FLOW_STEP,
			$lowest === null ? self::START_Y : $this->rowBelow($lowest),
		);

		$this->currentFlowBaseY = $nextBaseY;
		$this->occupied = [];
	}

	public function getFlowBaseY(): int
	{
		return $this->currentFlowBaseY;
	}

	public function calculatePosition(int $stepIndex): Position
	{
		return new Position(self::columnX($stepIndex), $this->yOverride ?? $this->currentFlowBaseY);
	}

	/**
	 * Tells the layout what a node built at a position takes on the canvas. Dimensions come from the node
	 * itself, so a node the descriptor states none for is counted as an ordinary one.
	 */
	public function noteNode(Position $position, int|float|null $width = null, int|float|null $height = null): void
	{
		$this->occupied[] = [
			'to' => $position->x + (int)ceil((float)($width ?? self::NODE_WIDTH)),
			'bottom' => $position->y + (int)ceil((float)($height ?? self::NODE_HEIGHT)),
		];
	}

	/**
	 * Shifts the cursor into a row of its own for a branch or a bound node starting at the given step index:
	 * below the row it leaves, and below every node already standing in the columns from that index on.
	 */
	public function shiftRow(int $fromStepIndex): void
	{
		$this->yStack[] = $this->yOverride;

		$parentY = $this->yOverride ?? $this->currentFlowBaseY;
		$lowest = $this->lowestEdgeFrom(self::columnX($fromStepIndex));
		$this->yOverride = max(
			$parentY + self::BRANCH_ROW_OFFSET,
			$lowest === null ? self::START_Y : $this->rowBelow($lowest),
		);
	}

	public function resetRow(): void
	{
		$this->yOverride = array_pop($this->yStack);
	}

	/**
	 * Keeps a row free under a composite node for the link its body returns by: the link runs back to the
	 * node from the right, so the reserve covers the columns of the composite and everything after them.
	 */
	public function reserveCompositeLoopback(Position $compositePosition): void
	{
		$lowest = $this->lowestEdgeFrom($compositePosition->x) ?? $compositePosition->y + self::NODE_HEIGHT;

		$this->occupied[] = [
			'to' => PHP_INT_MAX,
			'bottom' => $lowest + self::COMPOSITE_LOOPBACK_RESERVE,
		];
	}

	/**
	 * The lower edge of the lowest node reaching the given column, null when no node of the flow reaches it.
	 * A node standing entirely to the left of the column is out: nothing drawn there can cover it.
	 */
	private function lowestEdgeFrom(int $x): ?int
	{
		$lowest = null;

		foreach ($this->occupied as $node)
		{
			if ($node['to'] > $x && ($lowest === null || $node['bottom'] > $lowest))
			{
				$lowest = $node['bottom'];
			}
		}

		return $lowest;
	}

	/** The first row of the grid that leaves the clearance under the given lower edge free. */
	private function rowBelow(int $bottom): int
	{
		return (int)ceil(($bottom + self::ROW_CLEARANCE) / self::ROW_GRID) * self::ROW_GRID;
	}

	private static function columnX(int $stepIndex): int
	{
		return self::START_X + ($stepIndex * self::X_STEP);
	}
}

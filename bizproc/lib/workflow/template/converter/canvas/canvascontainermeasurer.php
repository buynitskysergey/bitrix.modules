<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Canvas;

final class CanvasContainerMeasurer
{
	public function measureContainer(CanvasContainer $container): CanvasSize
	{
		$padding = $container->findPadding();
		$itemSizes = array_map([$this, 'measureItem'], $container->findItems());
		if (empty($itemSizes))
		{
			return new CanvasSize($padding->top + $padding->bottom, $padding->left + $padding->right);
		}

		if ($container->findDirection() === CanvasDirection::HORIZONTAL)
		{
			$columns = array_sum(array_map(static fn(CanvasSize $size) => $size->columns, $itemSizes));
			$columns += max(count($itemSizes) - 1, 0) * $container->findColumnGap();
			$rows = max(array_map(static fn(CanvasSize $size) => $size->rows, $itemSizes));

			return new CanvasSize(
				$rows + $padding->top + $padding->bottom,
				$columns + $padding->left + $padding->right
			);
		}

		$rows = array_sum(array_map(static fn(CanvasSize $size) => $size->rows, $itemSizes));
		$rows += max(count($itemSizes) - 1, 0) * $container->findRowGap();
		$columns = max(array_map(static fn(CanvasSize $size) => $size->columns, $itemSizes));

		return new CanvasSize(
			$rows + $padding->top + $padding->bottom,
			$columns + $padding->left + $padding->right
		);
	}

	public function placeContainer(CanvasContainer $container, float $originRow = 0, float $originColumn = 0): array
	{
		$padding = $container->findPadding();
		$currentRow = $originRow + $padding->top + $container->findOffsetRow();
		$currentColumn = $originColumn + $padding->left + $container->findOffsetColumn();
		$placedItems = [];

		foreach ($container->findItems() as $item)
		{
			$size = $this->measureItem($item);
			$placedItems[] = new CanvasPlacedItem($item, $currentRow, $currentColumn, $size);

			if ($container->findDirection() === CanvasDirection::HORIZONTAL)
			{
				$currentColumn += $size->columns + $container->findColumnGap();
			}
			else
			{
				$currentRow += $size->rows + $container->findRowGap();
			}
		}

		return $placedItems;
	}

	private function measureItem(CanvasNode|CanvasContainer $item): CanvasSize
	{
		if ($item instanceof CanvasContainer)
		{
			return $this->measureContainer($item);
		}

		return new CanvasSize($item->findRowSpan(), $item->findColumnSpan());
	}
}

<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Canvas;

use Bitrix\Bizproc\Workflow\Template\Converter\Layout;

final class CanvasContainerLayoutAdapter
{
	public function __construct(
		private readonly CanvasLayoutAdapter $layoutAdapter = new CanvasLayoutAdapter(),
		private readonly CanvasContainerMeasurer $measurer = new CanvasContainerMeasurer(),
	) {}

	public function convertContainerToLayout(
		CanvasContainer $container,
		string $entryId,
		Layout\GridPoint $entryPoint,
		bool $shouldLinkEntryToFirstItem = true,
		?string $entryTargetPortId = null,
		?CanvasFragment $fragmentContext = null,
	): Layout\LayoutResult
	{
		$result = Layout\LayoutResult::createFromAnchor($entryId, $entryPoint);
		$currentEntryId = $entryId;
		$isFirstItem = true;

		foreach ($this->measurer->placeContainer($container, $entryPoint->row, $entryPoint->column) as $placedItem)
		{
			$item = $placedItem->item;
			$itemEntryPoint = new Layout\GridPoint($placedItem->row - 1, $placedItem->column);
			$itemLayout = $item instanceof CanvasContainer
				? $this->convertContainerToLayout(
					$item,
					$currentEntryId,
					$itemEntryPoint,
					$isFirstItem ? $shouldLinkEntryToFirstItem : true,
					$entryTargetPortId,
					$fragmentContext
				)
				: $this->convertNodeToLayout(
					$item,
					$currentEntryId,
					$itemEntryPoint,
					$isFirstItem ? $shouldLinkEntryToFirstItem : true,
					$entryTargetPortId,
					$fragmentContext
				)
			;

			$result->appendLayout($itemLayout);
			$currentEntryId = $itemLayout->exitId;
			$isFirstItem = false;
		}

		if ($frame = $container->findFrame())
		{
			$this->layoutAdapter->appendFrameToLayout($result, $frame);
		}

		$result->exitId = $currentEntryId;

		return $result;
	}

	private function convertNodeToLayout(
		CanvasNode $node,
		string $entryId,
		Layout\GridPoint $entryPoint,
		bool $shouldLinkEntryToNode,
		?string $entryTargetPortId = null,
		?CanvasFragment $fragmentContext = null,
	): Layout\LayoutResult
	{
		if ($fragmentContext)
		{
			return $this->layoutAdapter->convertFragmentNodeToLayout(
				$fragmentContext,
				$node,
				$entryId,
				$entryPoint
			);
		}

		$nodePoint = $entryPoint->moveBy(1, 0);
		$result = $this->layoutAdapter->createNodeLayout($node, $nodePoint);
		if ($shouldLinkEntryToNode)
		{
			$result->addLink(
				$entryId,
				$entryTargetPortId ? $node->findId() . ':' . $entryTargetPortId : $node->findId()
			);
		}
		$result->addAnchor($node->createOutputPortRef()->convertToString(), $nodePoint);
		$result->exitId = $node->createOutputPortRef()->convertToString();

		return $result;
	}
}

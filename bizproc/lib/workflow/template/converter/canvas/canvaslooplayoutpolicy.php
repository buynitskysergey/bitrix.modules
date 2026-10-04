<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Canvas;

use Bitrix\Bizproc\Workflow\Template\Converter\Layout;

final class CanvasLoopLayoutPolicy
{
	public function __construct(
		private readonly CanvasLayoutAdapter $layoutAdapter,
		private readonly CanvasNodeLayoutFactory $nodeLayoutFactory = new CanvasNodeLayoutFactory(),
	) {}

	public function convertToLayout(
		string $entryId,
		Layout\GridPoint $entryPoint,
		CanvasNode $activityNode,
		Layout\LayoutResult $bodyLayout,
		string $bodyOutputPortId,
		string $loopInputPortId,
		string $exitOutputPortId,
	): Layout\LayoutResult
	{
		$activityPoint = $entryPoint->moveBy(1, 0);
		$activityLayout = $this->nodeLayoutFactory->createLayout($activityNode, $activityPoint);
		$bodyEntryId = $activityNode->findId() . ':' . $bodyOutputPortId;
		$bodyEntryPoint = $activityPoint->moveBy(1, 0);
		$exitPoint = new Layout\GridPoint(
			max($activityPoint->row, $bodyLayout->frame->bottom),
			$activityPoint->column
		);

		$result = $activityLayout
			->appendLayout($bodyLayout)
			->addAnchor($bodyEntryId, $bodyEntryPoint)
			->addAnchor($activityNode->findId() . ':' . $exitOutputPortId, $exitPoint)
			->addLink($entryId, $activityNode->findId())
			->addLink($bodyLayout->exitId, $activityNode->findId() . ':' . $loopInputPortId)
		;
		$result->exitId = $activityNode->findId() . ':' . $exitOutputPortId;
		$this->layoutAdapter->appendFrameToLayout(
			$result,
			CanvasFrame::create(
				$frameId = $activityNode->findId() . '_frame',
				$activityNode->findActivity()['Properties']['Title'] ?? $activityNode->findActivity()['Type'] ?? null
			)->wrapNodes($this->collectFrameNodeIds($activityNode, $bodyLayout))
		);
		$frameBounds = $result->findNodeFrame($frameId);
		if ($frameBounds)
		{
			$exitAnchorId = $activityNode->findId() . ':' . $exitOutputPortId;
			$currentExitPoint = $result->findAnchor($exitAnchorId);
			$result->addAnchor($exitAnchorId, new Layout\GridPoint($frameBounds->bottom, $currentExitPoint->column));
		}

		return $result;
	}

	private function collectFrameNodeIds(CanvasNode $activityNode, Layout\LayoutResult $bodyLayout): array
	{
		$nodeIds = [$activityNode->findId()];

		foreach ($bodyLayout->children as $child)
		{
			if (($child['Type'] ?? null) !== 'Merge')
			{
				$nodeIds[] = $child['Name'];
			}
		}

		return array_values(array_unique($nodeIds));
	}
}

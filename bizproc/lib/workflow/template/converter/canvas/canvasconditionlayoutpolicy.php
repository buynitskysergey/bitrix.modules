<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Canvas;

use Bitrix\Bizproc\Workflow\Template\Converter\Layout;

final class CanvasConditionLayoutPolicy
{
	public function __construct(
		private readonly CanvasLayoutAdapter $layoutAdapter,
		private readonly CanvasNodeLayoutFactory $nodeLayoutFactory = new CanvasNodeLayoutFactory(),
	) {}

	public function convertToLayout(
		string $entryId,
		Layout\GridPoint $entryPoint,
		CanvasNode $activityNode,
		CanvasBranch $branch,
		int $branchShift,
	): Layout\LayoutResult
	{
		$activityPoint = $entryPoint->moveBy(1, 0);
		$result = $this->nodeLayoutFactory->createLayout($activityNode, $activityPoint)
			->addLink($entryId, $activityNode->findId())
		;
		$trueBranchFragment = $branch->findFragmentByPortId('o0') ?? CanvasFragment::create();
		$trueEntryId = $activityNode->findId() . ':o0';
		$trueEntryPoint = new Layout\GridPoint($activityPoint->row + 1, $activityPoint->column);
		$trueBranchLayout = $this->layoutAdapter->convertFragmentToLayout($trueBranchFragment, $trueEntryId, $trueEntryPoint);
		$result->appendLayout($trueBranchLayout);

		$mergeNode = $branch->findMergeNode();
		if (!$mergeNode)
		{
			throw new \LogicException("Missing merge node for condition branch {$activityNode->findId()}");
		}

		if ($trueBranchLayout->children)
		{
			$result->addLink($trueBranchLayout->exitId, $mergeNode->findId());
		}
		else
		{
			$result->addLink($activityNode->findId() . ':o0', $mergeNode->findId());
		}

		$result->addAnchor(
			$activityNode->findId() . ':o1',
			new Layout\GridPoint($activityPoint->row - 1, $activityPoint->column + $branchShift)
		);
		$result->exitId = $activityNode->findId() . ':o1';

		return $result;
	}
}

<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Canvas;

use Bitrix\Bizproc\Workflow\Template\Converter\Layout;

final class CanvasLayoutAdapter
{
	private const COLUMN_GAP = 1;
	private const CONDITION_BRANCH_SHIFT = 1;
	private const FRAME_PADDING = 0;
	private ?CanvasNodeLayoutFactory $nodeLayoutFactory = null;
	private ?CanvasConditionLayoutPolicy $conditionLayoutPolicy = null;
	private ?CanvasLoopLayoutPolicy $loopLayoutPolicy = null;

	public function convertFragmentToLayout(
		CanvasFragment $fragment,
		string $entryId,
		Layout\GridPoint $entryPoint,
	): Layout\LayoutResult
	{
		if ($fragment->findRootContainer())
		{
			$result = (new CanvasContainerLayoutAdapter($this))->convertContainerToLayout(
				$fragment->findRootContainer(),
				$entryId,
				$entryPoint,
				true,
				null,
				$fragment
			);

			$this->appendFramesToLayout($result, $fragment->findFrames());

			$exitPort = $fragment->findExitPort();
			if ($exitPort)
			{
				$exitId = $exitPort->convertToString();
				if (!isset($result->anchors[$exitId]))
				{
					$result->addAnchor($exitId, $result->findAnchor($exitPort->nodeId));
				}
				$this->moveExitAnchorBelowFrame($result, $fragment->findFrames(), $exitPort);
				$result->exitId = $exitId;
			}

			return $result;
		}

		$result = Layout\LayoutResult::createFromAnchor($entryId, $entryPoint);
		$currentExitId = $entryId;
		$currentExitPoint = $entryPoint;
		$processedNodeIds = [];

		foreach ($fragment->findNodes() as $node)
		{
			$nodeEntryPoint = $this->resolveNodeEntryPoint($result, $currentExitId, $currentExitPoint, $node);
			$nodeLayout = $this->convertNodeToLayout($fragment, $node, $currentExitId, $nodeEntryPoint);
			$result->appendLayout($nodeLayout);
			$currentExitId = $nodeLayout->exitId;
			$currentExitPoint = $nodeLayout->findAnchor($currentExitId);
			$processedNodeIds[$node->findId()] = true;
			$currentExitPoint = $this->resolveFrameAwareExitPoint(
				$result,
				$fragment->findFrames(),
				$currentExitId,
				$currentExitPoint,
				$processedNodeIds
			);
		}

		foreach ($fragment->findFrames() as $frame)
		{
			$this->appendFrameToLayout($result, $frame);
		}

		$exitPort = $fragment->findExitPort();
		if ($exitPort)
		{
			$exitId = $exitPort->convertToString();
			if (!isset($result->anchors[$exitId]))
			{
				$result->addAnchor($exitId, $result->findAnchor($exitPort->nodeId));
			}
			$this->moveExitAnchorBelowFrame($result, $fragment->findFrames(), $exitPort);
			$result->exitId = $exitId;
		}
		else
		{
			$result->exitId = $currentExitId;
		}

		return $result;
	}

	private function resolveFrameAwareExitPoint(
		Layout\LayoutResult $layout,
		array $frames,
		string $exitId,
		Layout\GridPoint $exitPoint,
		array $processedNodeIds,
	): Layout\GridPoint
	{
		$exitNodeId = PortRef::createFromNodeId($exitId, 'o0')->nodeId;

		foreach ($frames as $frame)
		{
			$wrappedNodeIds = $frame->findWrappedNodeIds();
			if (!in_array($exitNodeId, $wrappedNodeIds, true))
			{
				continue;
			}

			if (array_diff($wrappedNodeIds, array_keys($processedNodeIds)))
			{
				continue;
			}

			$bounds = $layout->calculateNodeBoundsByNames($wrappedNodeIds);

			return new Layout\GridPoint(
				$bounds->bottom + (self::FRAME_PADDING * 2),
				$exitPoint->column
			);
		}

		return $exitPoint;
	}

	public function convertFragmentNodeToLayout(
		CanvasFragment $fragment,
		CanvasNode $node,
		string $entryId,
		Layout\GridPoint $entryPoint,
	): Layout\LayoutResult
	{
		$nodeEntryPoint = $this->resolveNodeEntryPoint(
			Layout\LayoutResult::createFromAnchor($entryId, $entryPoint),
			$entryId,
			$entryPoint,
			$node
		);

		return $this->convertNodeToLayout($fragment, $node, $entryId, $nodeEntryPoint);
	}

	private function resolveNodeEntryPoint(
		Layout\LayoutResult $layout,
		string $entryId,
		Layout\GridPoint $entryPoint,
		CanvasNode $node,
	): Layout\GridPoint
	{
		$activityType = $node->findActivity()['Type'] ?? null;
		if ($activityType === 'Merge' && str_ends_with($entryId, ':o1'))
		{
			return new Layout\GridPoint(
				$layout->frame->bottom,
				$this->calculateConditionMergeColumn($layout, $entryPoint->column)
			);
		}

		return $entryPoint;
	}

	private function calculateConditionMergeColumn(Layout\LayoutResult $layout, float $defaultColumn): float
	{
		foreach ($layout->children as $child)
		{
			if (($child['Type'] ?? null) === 'IfElseBranchActivity')
			{
				return $layout->findAnchor($child['Name'])->column;
			}
		}

		return $defaultColumn;
	}

	private function moveExitAnchorBelowFrame(
		Layout\LayoutResult $layout,
		array $frames,
		PortRef $exitPort,
	): void
	{
		foreach ($frames as $frame)
		{
			if (!in_array($exitPort->nodeId, $frame->findWrappedNodeIds(), true))
			{
				continue;
			}

			$frameBounds = $layout->findNodeFrame($frame->findId());
			if (!$frameBounds)
			{
				continue;
			}

			$exitId = $exitPort->convertToString();
			$currentPoint = $layout->findAnchor($exitId);
			$layout->addAnchor($exitId, new Layout\GridPoint($frameBounds->bottom, $currentPoint->column));

			return;
		}
	}

	public function convertLinearFragmentToLayout(
		CanvasFragment $fragment,
		string $entryId,
		Layout\GridPoint $entryPoint,
	): Layout\LayoutResult
	{
		$result = Layout\LayoutResult::createFromAnchor($entryId, $entryPoint);
		$nodePoints = [];
		$currentPoint = $entryPoint;

		foreach ($fragment->findNodes() as $node)
		{
			$currentPoint = $currentPoint->moveBy(1, 0);
			$nodePoints[$node->findId()] = $currentPoint;
			$result->appendLayout($this->createNodeLayout($node, $currentPoint));
		}

		foreach ($fragment->findLinks() as $link)
		{
			$result->links[] = $link->convertToArray();
		}

		$exitPort = $fragment->findExitPort();
		if ($exitPort && isset($nodePoints[$exitPort->nodeId]))
		{
			$result->addAnchor($exitPort->convertToString(), $nodePoints[$exitPort->nodeId]);
			$result->exitId = $exitPort->convertToString();
		}

		return $result;
	}

	public function convertHorizontalFragmentToLayout(
		CanvasFragment $fragment,
		string $entryId,
		Layout\GridPoint $entryPoint,
		bool $linkEntryToFirstNode = true,
	): Layout\LayoutResult
	{
		$result = Layout\LayoutResult::createFromAnchor($entryId, $entryPoint);
		$currentPoint = $entryPoint;
		$isFirstNode = true;
		$lastNodeId = $entryId;

		foreach ($fragment->findNodes() as $node)
		{
			$currentPoint = $isFirstNode
				? $entryPoint->moveBy(1, 0)
				: $currentPoint->moveBy(0, 1)
			;
			$result->appendLayout($this->createNodeLayout($node, $currentPoint));
			if ($isFirstNode && $linkEntryToFirstNode)
			{
				$result->addLink($entryId, $node->findId());
			}

			$lastNodeId = $node->findId();
			$isFirstNode = false;
		}

		foreach ($fragment->findLinks() as $link)
		{
			$result->links[] = $link->convertToArray();
		}

		$result->exitId = $lastNodeId;

		return $result;
	}

	public function convertAuxiliaryFragmentToLayout(
		CanvasFragment $fragment,
		string $parentNodeId,
		string $parentPortId,
		Layout\GridPoint $parentPoint,
		string $childPortId,
	): Layout\LayoutResult
	{
		$result = Layout\LayoutResult::createFromAnchor($parentNodeId, $parentPoint);
		$currentPoint = $parentPoint;
		$lastNodeId = $parentNodeId;

		foreach ($fragment->findNodes() as $node)
		{
			$currentPoint = $currentPoint->moveBy(1, 0);
			$result->appendLayout($this->createNodeLayout($node, $currentPoint));
			$result->addLink(
				$parentNodeId . ':' . $parentPortId,
				$node->findId() . ':' . $childPortId
			);
			$lastNodeId = $node->findId();
		}

		foreach ($fragment->findLinks() as $link)
		{
			$result->links[] = $link->convertToArray();
		}

		$result->exitId = $lastNodeId;

		return $result;
	}

	public function appendFramesToLayout(Layout\LayoutResult $layout, array $frames): Layout\LayoutResult
	{
		foreach ($frames as $frame)
		{
			$this->appendFrameToLayout($layout, $frame);
		}

		return $layout;
	}

	public function appendFrameToLayout(Layout\LayoutResult $layout, CanvasFrame $frame): Layout\LayoutResult
	{
		$wrappedNodeIds = $frame->findWrappedNodeIds();
		if (empty($wrappedNodeIds))
		{
			return $layout;
		}

		$layout->moveNodesByNames($wrappedNodeIds, self::FRAME_PADDING, self::FRAME_PADDING);
		$bounds = $layout->calculateNodeBoundsByNames($wrappedNodeIds);
		$bounds = $bounds->expand(
			self::FRAME_PADDING,
			self::FRAME_PADDING,
			self::FRAME_PADDING,
			self::FRAME_PADDING
		);
		$activity = [
			'Type' => 'EmptyBlockActivity',
			'Name' => $frame->findId(),
			'Properties' => [
				'Title' => $frame->findTitle(),
			],
		];

		$layout->appendLayout(Layout\LayoutResult::createFromNode(
			$activity,
			new Layout\GridPoint($bounds->top, $bounds->left),
			$bounds
		));

		return $layout;
	}

	public function convertTriggerFragmentToLayout(
		CanvasFragment $fragment,
		CanvasNode $mergeNode,
		Layout\GridPoint $mergePoint,
	): Layout\LayoutResult
	{
		$result = Layout\LayoutResult::createFromNode($mergeNode->findActivity(), $mergePoint);
		$triggerPoint = new Layout\GridPoint(1, $mergePoint->column - 1);

		foreach ($fragment->findNodes() as $triggerNode)
		{
			$result->appendLayout($this->createNodeLayout($triggerNode, $triggerPoint));
			$result->addLink($triggerNode->findId(), $mergeNode->findId());
			$triggerPoint = $triggerPoint->moveBy(1, 0);
		}

		return $result;
	}

	public function convertLoopNodeToLayout(
		string $entryId,
		Layout\GridPoint $entryPoint,
		CanvasNode $activityNode,
		Layout\LayoutResult $bodyLayout,
		string $bodyOutputPortId,
		string $loopInputPortId,
		string $exitOutputPortId,
	): Layout\LayoutResult
	{
		return $this->getLoopLayoutPolicy()->convertToLayout(
			$entryId,
			$entryPoint,
			$activityNode,
			$bodyLayout,
			$bodyOutputPortId,
			$loopInputPortId,
			$exitOutputPortId
		);
	}

	private function convertNodeToLayout(
		CanvasFragment $fragment,
		CanvasNode $node,
		string $entryId,
		Layout\GridPoint $entryPoint,
	): Layout\LayoutResult
	{
		$branch = $fragment->findBranchByNodeId($node->findId());
		$activityType = $node->findActivity()['Type'] ?? null;

		if ($branch && ($activityType === 'WhileActivity' || $activityType === 'ForEachActivity'))
		{
			$bodyFragment = $branch->findFragmentByPortId('o0') ?? CanvasFragment::create();
			$bodyEntryId = $node->findId() . ':o0';
			$bodyEntryPoint = $entryPoint->moveBy(2, 0);
			$bodyLayout = $this->convertFragmentToLayout($bodyFragment, $bodyEntryId, $bodyEntryPoint);

			return $this->convertLoopNodeToLayout(
				$entryId,
				$entryPoint,
				$node,
				$bodyLayout,
				'o0',
				$activityType === 'WhileActivity' ? 'i0' : 'i1',
				'o1'
			);
		}

		if ($branch && $activityType === 'IfElseBranchActivity')
		{
			return $this->getConditionLayoutPolicy()->convertToLayout(
				$entryId,
				$entryPoint,
				$node,
				$branch,
				self::CONDITION_BRANCH_SHIFT
			);
		}

		if ($branch)
		{
			$branchLayoutsByPortId = [];
			foreach ($branch->findPortIds() as $portId)
			{
				$branchFragment = $branch->findFragmentByPortId($portId) ?? CanvasFragment::create();
				$branchLayoutsByPortId[$portId] = $this->convertFragmentToLayout(
					$branchFragment,
					$node->findId() . ':' . $portId,
					new Layout\GridPoint(1, 0)
				);
			}

			$mergeNode = $branch->findMergeNode();
			if (!$mergeNode)
			{
				throw new \LogicException("Missing merge node for branch {$node->findId()}");
			}
			$mergeFlow = ($mergeNode->findActivity()['Type'] ?? null) === 'MergeFlowNode';

			return $this->convertBranchNodeToLayout(
				$entryId,
				$entryPoint,
				$node,
				$branch,
				$branchLayoutsByPortId,
				$mergeNode->findActivity(),
				!$mergeFlow,
				$mergeFlow
			);
		}

		return $this->convertSingleNodeToLayout($entryId, $entryPoint, $node);
	}

	private function convertSingleNodeToLayout(
		string $entryId,
		Layout\GridPoint $entryPoint,
		CanvasNode $node,
	): Layout\LayoutResult
	{
		$nodePoint = $entryPoint->moveBy(1, 0);
		$result = $this->createNodeLayout($node, $nodePoint)
			->addLink($entryId, $node->findId())
			->addAnchor($node->createOutputPortRef()->convertToString(), $nodePoint);
		$result->exitId = $node->createOutputPortRef()->convertToString();

		return $result;
	}

	public function createNodeLayout(CanvasNode $node, Layout\GridPoint $point): Layout\LayoutResult
	{
		return $this->getNodeLayoutFactory()->createLayout($node, $point);
	}

	public function convertBranchNodeToLayout(
		string $entryId,
		Layout\GridPoint $entryPoint,
		CanvasNode $activityNode,
		CanvasBranch $branch,
		array $branchLayoutsByPortId,
		array $mergeActivity,
		bool $moveActivityDown = true,
		bool $mergeFlow = false,
	): Layout\LayoutResult
	{
		$activityPoint = $moveActivityDown ? $entryPoint->moveBy(1, 0) : $entryPoint;
		$layout = Layout\LayoutResult::createFromNode($activityNode->findActivity(), $activityPoint)
			->addLink($entryId, $activityNode->findId())
		;
		$tails = [];
		$orderedBranchLayouts = [];

		foreach ($branch->findPortIds() as $portId)
		{
			if (isset($branchLayoutsByPortId[$portId]))
			{
				$orderedBranchLayouts[$portId] = $branchLayoutsByPortId[$portId];
			}
		}

		$this->distributeBranchLayouts($orderedBranchLayouts, $activityPoint, $mergeFlow);

		foreach ($orderedBranchLayouts as $portId => $branchLayout)
		{
			$layout->appendLayout($branchLayout);
			$layout->addAnchor($activityNode->findId() . ':' . $portId, $branchLayout->findAnchor($activityNode->findId() . ':' . $portId));
			$tails[] = $branchLayout->exitId;
		}

		$mergeRow = $this->calculateMaxAnchorRow($layout, [$activityNode->findId(), ...$tails]) + ($mergeFlow ? 1 : 0);
		$mergePoint = new Layout\GridPoint($mergeRow, $activityPoint->column);
		$layout->appendLayout(Layout\LayoutResult::createFromNode($mergeActivity, $mergePoint));
		foreach ($tails as $tailId)
		{
			$layout->addLink($tailId, $mergeActivity['Name']);
		}
		$layout->exitId = $mergeActivity['Name'];

		return $layout;
	}

	private function distributeBranchLayouts(
		array $branchLayoutsByPortId,
		Layout\GridPoint $activityPoint,
		bool $alignFromActivityColumn = false,
	): void
	{
		$totalWidth = 0;
		foreach ($branchLayoutsByPortId as $layout)
		{
			$totalWidth += $layout->frame->calculateWidth();
		}
		$totalWidth += max(count($branchLayoutsByPortId) - 1, 0) * self::COLUMN_GAP;

		$cursor = $alignFromActivityColumn
			? $activityPoint->column
			: $activityPoint->column - ($totalWidth / 2) + 0.5
		;

		foreach ($branchLayoutsByPortId as $layout)
		{
			$layout->moveBy($activityPoint->row, $cursor - $layout->frame->left);
			$cursor = $layout->frame->right + self::COLUMN_GAP + 1;
		}
	}

	private function calculateMaxAnchorRow(Layout\LayoutResult $layout, array $anchorIds): float
	{
		$rows = [];
		foreach ($anchorIds as $anchorId)
		{
			$rows[] = $layout->findAnchor($anchorId)->row;
		}

		return max($rows);
	}

	private function getNodeLayoutFactory(): CanvasNodeLayoutFactory
	{
		$this->nodeLayoutFactory ??= new CanvasNodeLayoutFactory();

		return $this->nodeLayoutFactory;
	}

	private function getConditionLayoutPolicy(): CanvasConditionLayoutPolicy
	{
		$this->conditionLayoutPolicy ??= new CanvasConditionLayoutPolicy($this, $this->getNodeLayoutFactory());

		return $this->conditionLayoutPolicy;
	}

	private function getLoopLayoutPolicy(): CanvasLoopLayoutPolicy
	{
		$this->loopLayoutPolicy ??= new CanvasLoopLayoutPolicy($this, $this->getNodeLayoutFactory());

		return $this->loopLayoutPolicy;
	}
}

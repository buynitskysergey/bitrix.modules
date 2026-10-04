<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter;

use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Internal\Helper\Activity\ActivityHelper;
use Bitrix\Bizproc\Workflow\Template\Converter\Canvas\CanvasFragment;
use Bitrix\Bizproc\Workflow\Template\Converter\Canvas\CanvasContainer;
use Bitrix\Bizproc\Workflow\Template\Converter\Canvas\CanvasContainerLayoutAdapter;

/** @noinspection AutoloadingIssuesInspection */
final class StateMachineToNodeWorkflow extends SequentialToNodeWorkflow
{
	private const STATE_CHILD_FRAME_GAP = 1;
	private const STATE_CHILD_COLUMN_OFFSET = 3;

	protected array $stateNames;
	private float $stateChildFrameTopRow = 4;

	/** @noinspection PhpMissingParentConstructorInspection */
	public function __construct(array $template)
	{
		$rootActivity = $template[0] ?? null;

		if (($rootActivity['Type'] ?? '') !== 'StateMachineWorkflowActivity')
		{
			throw new \CBPArgumentException('root activity needs to be a StateMachineWorkflowActivity');
		}

		$this->rootActivity = $rootActivity;
		$this->stateNames = array_column($rootActivity['Children'], 'Name');
		$this->setStartTrigger('ManualStartTrigger');
	}

	protected function buildLayout(): Layout\LayoutResult
	{
		[$startName, $layout] = $this->createTriggers();

		$startPoint = $layout->calculateNodeBounds()->getRightBottomPoint()->moveBy(1, 1);

		foreach ($this->rootActivity['Children'] as $i => $state)
		{
			$state['Type'] = 'StateNode';
			$stateContainer = CanvasContainer::create();
			$stateContainer->appendNode($this->createCanvasNode($state));

			$statesLayout = (new CanvasContainerLayoutAdapter($this->getCanvasLayoutAdapter()))->convertContainerToLayout(
				$stateContainer,
				'',
				$startPoint->moveBy($i, ($i % 2))
			);
			$layout->appendLayout($statesLayout);

			//$this->createStateChildLinks($state, $state['Name'], $layout);
		}

		foreach ($this->rootActivity['Children'] as $state)
		{
			$layout->appendLayout(
				$this->convertStateChildren(
					$state,
					$layout->findAnchor($state['Name'])
				)
			);
		}

		[$layout->links, $layout->children] = $this->optimizeChildren(
			$layout->links,
			$layout->children
		);

		return $layout;
	}

	protected function createTriggers(): array
	{
		[$startName, $layout] = parent::createTriggers();

		$startStateName = $this->rootActivity['Children'][0]['Name'] ?? null;
		if ($startStateName)
		{
			$activityDescription = \CBPRuntime::getRuntime()->getActivityDescription('SetStateNode');
			$name = ActivityHelper::generateName();

			$layout->appendLayout(Layout\LayoutResult::createFromNode(
				[
					'Name' => $name,
					'Type' => $activityDescription['CLASS'],
					'Properties' => [
						'TargetStateName' => $startStateName,
						'Title' => $activityDescription['NAME'] ?? null
					],
				],
				$layout->calculateNodeBounds()->getRightBottomPoint()
			));

			$layout->addLink($startName, $name);
		}

		return [$startName, $layout];
	}

	private function convertStateChildren(array $state, Layout\GridPoint $statePoint): Layout\LayoutResult
	{
		$result = Layout\LayoutResult::createFromAnchor($state['Name'], $statePoint);
		$innerLayouts = [];

		foreach ($state['Children'] as $stateChild)
		{
			['activity' => $child, 'innerLayout' => $childInnerLayout] = $this->convertStateChild($stateChild);
			if ($childInnerLayout)
			{
				$innerLayouts[] = [$child, $childInnerLayout];
			}
		}

		foreach ($innerLayouts as [$child, $childInnerLayout, $stateChild])
		{
			$result->appendLayout($this->moveStateChildLayoutToCanvas($child, $childInnerLayout));

			if (isset($child['Children'][0]['Children'][0]['Name']))
			{
				$outPortName = match ($child['Type'])
				{
					'StateInitializationActivity' => ':o0',
					'StateFinalizationActivity' => ':o1',
					default => ':o2',
				};

				$result->addLink($state['Name'] . $outPortName, $child['Children'][0]['Children'][0]['Name'] . ':i0');
			}
		}

		$frame = CanvasFragment::createFromActivities($result->children)->wrapInFrame(
			$state['Name'] . '_frame',
			$state['Properties']['Title'] ?? $state['Name']
		);
		$this->getCanvasLayoutAdapter()->appendFrameToLayout($result, $frame);

		return $result;
	}

	private function convertStateChild(array $child): array
	{
		//if ($child['Type'] === 'EventDrivenActivity')
		//{
		//	$isDelay = ($child['Children'][0]['Type'] ?? '') === 'DelayActivity';
		//	$child['PresetId'] = $isDelay ? 'DELAY' : 'CMD';
		//	$child['Properties']['Title'] = $child['Children'][0]['Properties']['Title'];
		//}

		if (
			$child['Type'] === 'StateInitializationActivity'
			|| $child['Type'] === 'StateFinalizationActivity'
			|| $child['Type'] === 'EventDrivenActivity'
		)
		{
			$converter = new StateChildToNodeWorkflow($child);
			$conversion = $converter->convertAndExtractLayout();
			$child['Children'] = $conversion['template'];

			return [
				'activity' => $child,
				'innerLayout' => $this->extractStateChildInnerLayout($child, $conversion['layout']),
			];
		}

		throw new \CBPArgumentException('unexpected child activity type in StateActivity: ' . $child['Type']);
	}

	private function createStateChildLinks(array $child, string $outputName, Layout\LayoutResult $layout): array
	{
		$links = [];
		foreach ($this->walkChildren($child) as $activity)
		{
			if ($activity['Type'] === 'SetStateActivity')
			{
				$targetState = $activity['Properties']['TargetStateName'] ?? null;
				if ($targetState && in_array($targetState, $this->stateNames, true))
				{
					$layout->addLink($outputName, $targetState);
				}
			}
		}

		return $links;
	}

	private function walkChildren(array $activity): iterable
	{
		yield $activity;

		if (is_array($activity['Children'] ?? null))
		{
			foreach ($activity['Children'] as $child)
			{
				foreach ($this->walkChildren($child) as $descendant)
				{
					yield $descendant;
				}
			}
		}
	}

	private function extractStateChildInnerLayout(array $child, Layout\LayoutResult $layout): ?Layout\LayoutResult
	{
		$children = array_values(
			$layout->children,
		);

		if (empty($children))
		{
			return null;
		}

		$childNames = array_column($children, 'Name');
		$allowedNames = $childNames;
		$links = array_values(array_filter(
			$layout->links,
			fn(array $link) => $this->isStateChildInnerLink($link, $allowedNames)
		));
		$bounds = $layout->calculateNodeBoundsByNames($childNames);
		$result = new Layout\LayoutResult($child['Name'], $bounds);
		$result->children = $children;
		$result->links = $links;

		foreach ($childNames as $childName)
		{
			if (isset($layout->anchors[$childName]))
			{
				$result->anchors[$childName] = $layout->anchors[$childName];
			}

			$frame = $layout->findNodeFrame($childName);
			if ($frame)
			{
				$result->nodeFrames[$childName] = $frame;
			}
		}

		return $result;
	}

	private function isStateChildInnerLink(array $link, array $allowedNames): bool
	{
		[$sourceName, $targetName] = array_map(
			[$this, 'extractNodeNameFromLink'],
			array_slice($link, 0, 2)
		);

		return in_array($sourceName, $allowedNames, true) && in_array($targetName, $allowedNames, true);
	}

	private function extractNodeNameFromLink(string $nodeId): string
	{
		return explode(':', $nodeId, 2)[0];
	}

	private function moveStateChildLayoutToCanvas(array $child, Layout\LayoutResult $layout): Layout\LayoutResult
	{
		$childNames = array_column($layout->children, 'Name');
		$bounds = $layout->calculateNodeBoundsByNames($childNames);
		$layout->moveBy(
			$this->stateChildFrameTopRow - $bounds->top,
			self::STATE_CHILD_COLUMN_OFFSET
		);

		$this->stateChildFrameTopRow += $bounds->calculateHeight() + self::STATE_CHILD_FRAME_GAP;

		return $layout;
	}

	protected function makeNodeSettings(array $activity): array
	{
		$settings = parent::makeNodeSettings($activity);
		if ($activity['Type'] === 'StateNode')
		{
			$settings['ports'] = \CBPRuntime::getRuntime()->getActivityDescription($activity['Type'])['NODE_SETTINGS']['ports'];
		}

		return $settings;
	}
}

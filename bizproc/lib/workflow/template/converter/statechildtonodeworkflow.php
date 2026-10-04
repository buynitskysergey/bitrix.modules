<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter;

use Bitrix\Bizproc\Workflow\Template\Converter\Canvas\CanvasContainer;
use Bitrix\Bizproc\Workflow\Template\Converter\Canvas\CanvasFragment;
use Bitrix\Bizproc\Workflow\Template\Converter\Canvas\CanvasNode;

final class StateChildToNodeWorkflow extends SequentialToNodeWorkflow
{
	/** @noinspection PhpMissingParentConstructorInspection */
	public function __construct(array $stateActivity)
	{
		if (
			$stateActivity['Type'] !== 'StateInitializationActivity'
			&& $stateActivity['Type'] !== 'StateFinalizationActivity'
			&& $stateActivity['Type'] !== 'EventDrivenActivity'
		)
		{
			throw new \CBPArgumentException(
				'unexpected state activity type ' . $stateActivity['Type']
			);
		}

		$this->rootActivity = $stateActivity;
		//$this->setStartTrigger($stateActivity['Type']);
	}

	protected function buildChildFragment(array $child): CanvasFragment
	{
		if ($child['Type'] === 'SetStateActivity')
		{
			$child['Type'] = 'SetStateNode';
		}

		return parent::buildChildFragment($child);
	}

	protected function createTrigger(string $type): array
	{
		return $this->rootActivity;
	}
}

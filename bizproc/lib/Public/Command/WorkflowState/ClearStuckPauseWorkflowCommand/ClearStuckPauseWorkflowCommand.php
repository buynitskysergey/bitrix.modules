<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\WorkflowState\ClearStuckPauseWorkflowCommand;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;

class ClearStuckPauseWorkflowCommand extends AbstractCommand
{
	private const DEFAULT_LIMIT = 50;
	public readonly int $limit;

	public function __construct(public readonly ?StuckPauseSweepCursor $cursor = null)
	{
		$this->limit = max(1, (int)Option::get('bizproc', 'clear_stuck_pause_workflow_limit', self::DEFAULT_LIMIT));
	}

	protected function execute(): ClearStuckPauseWorkflowResult
	{
		return ServiceLocator::getInstance()->get('bizproc.clear.stuck.pause.workflow.command.handler')($this);
	}
}

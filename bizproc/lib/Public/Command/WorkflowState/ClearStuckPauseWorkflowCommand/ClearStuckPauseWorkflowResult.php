<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\WorkflowState\ClearStuckPauseWorkflowCommand;

use Bitrix\Main\Result;

class ClearStuckPauseWorkflowResult extends Result
{
	// A null cursor means the chain is finished: every stage of the window is drained.
	public function __construct(public readonly ?StuckPauseSweepCursor $nextCursor = null)
	{
		parent::__construct();
	}
}

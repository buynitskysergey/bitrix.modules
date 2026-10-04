<?php

namespace Bitrix\Bizproc\Activity\Enum;

enum ResumeWorkflowQueue: string
{
	case Delay = 'resume_workflow_delay_queue';
	case RobotDelay = 'resume_workflow_robot_delay_queue';
	case WaitWorkDay = 'resume_workflow_wait_workday_queue';

	/** @deprecated Processes messages created before the queues were split */
	case Legacy = 'resume_workflow_queue';

	/**
	 * @return string[]
	 */
	public static function values(): array
	{
		return array_column(self::cases(), 'value');
	}
}

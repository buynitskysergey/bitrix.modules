<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\WorkflowState;

final class PauseActivityType
{
	public const DELAY = 'DelayActivity';
	public const ROBOT_DELAY = 'RobotDelayActivity';
	public const WAIT_WORK_DAY = 'WaitWorkDayActivity';

	public const ALL = [
		self::DELAY,
		self::ROBOT_DELAY,
		self::WAIT_WORK_DAY,
	];

	private function __construct()
	{
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Agent;

use Bitrix\Bizproc\Public\Command\WorkflowState\ClearStuckPauseWorkflowCommand\ClearStuckPauseWorkflowCommand;
use Bitrix\Bizproc\Public\Command\WorkflowState\ClearStuckPauseWorkflowCommand\ClearStuckPauseWorkflowResult;
use Bitrix\Bizproc\Public\Command\WorkflowState\ClearStuckPauseWorkflowCommand\StuckPauseSweepCursor;
use Bitrix\Main\Config\Option;

/**
 * Removes stuck workflows paused on DelayActivity, RobotDelayActivity, or WaitWorkDayActivity.
 */
class ClearStuckPauseWorkflowAgent
{
	private const ACTIVE_OPTION = 'clear_stuck_pause_agent_active';
	private const DEFAULT_OFFSET = 20;
	private const DEFAULT_INTERVAL = 86400;
	private const DELETE_AGENT_RESULT = '';

	public static function getName(): string
	{
		return self::class . '::run();';
	}

	/**
	 * Null until the option is written explicitly: a portal whose resume messages are still being
	 * backfilled with their ITEM_ID has no value yet, and there the sweep must not run.
	 */
	public static function getActiveOptionValue(): ?string
	{
		return Option::getRealValue('bizproc', self::ACTIVE_OPTION, siteId: '');
	}

	public static function register(): void
	{
		// During pagination the agent row holds a name like ::run('id'); which would bypass
		// the exact-name dedup inside CAgent::AddAgent(), creating a duplicate base agent.
		$alreadyExists = (bool)\CAgent::GetList(
			['ID' => 'ASC'],
			[
				'MODULE_ID' => 'bizproc',
				'NAME' => self::class . '::run(%',
			],
		)->Fetch();

		if (!$alreadyExists)
		{
			\CAgent::AddAgent(
				name: self::getName(),
				module: 'bizproc',
				interval: self::DEFAULT_INTERVAL,
				next_exec: \ConvertTimeStamp(time() + \CTimeZone::GetOffset() + 600, 'FULL'),
				existError: false,
			);
		}

		Option::set('bizproc', self::ACTIVE_OPTION, 'Y');
	}

	public static function unregister(): void
	{
		$agentIterator = \CAgent::GetList(
			['ID' => 'ASC'],
			[
				'MODULE_ID' => 'bizproc',
				'NAME' => self::class . '::run(%',
			],
		);

		while ($agent = $agentIterator->Fetch())
		{
			\CAgent::Delete($agent['ID']);
		}

		Option::set('bizproc', self::ACTIVE_OPTION, 'N');
	}

	private static function next(?string $cursor = null): string
	{
		if ($cursor !== null && $cursor !== '')
		{
			return self::class . '::run(' . var_export($cursor, true) . ');';
		}

		return self::getName();
	}

	public static function run(?string $cursor = null): string
	{
		if (self::getActiveOptionValue() !== 'Y')
		{
			return self::DELETE_AGENT_RESULT;
		}

		$command = new ClearStuckPauseWorkflowCommand(StuckPauseSweepCursor::tryParse($cursor));
		/** @var ClearStuckPauseWorkflowResult $result */
		$result = $command->run();

		global $pPERIOD;
		if ($result->nextCursor !== null)
		{
			$pPERIOD = max(1, (int)Option::get('bizproc', 'clear_stuck_pause_workflow_offset', self::DEFAULT_OFFSET));

			return self::next((string)$result->nextCursor);
		}

		$pPERIOD = strtotime('tomorrow 01:00') - time();

		return self::next();
	}
}

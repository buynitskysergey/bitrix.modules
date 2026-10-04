<?php

declare(strict_types=1);

namespace Bitrix\Crm\Agent\Copilot;

use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Crm\Copilot\CallScriptMaintenance\Config;
use Bitrix\Crm\Copilot\CallScriptMaintenance\Orchestrator;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Main\Type\DateTime;

final class CallScriptMaintenanceAgent extends AgentBase
{
	private const AGENT_CONTINUE = true;
	private const WEEK_IN_SECONDS = 604800;

	public const AGENT_NAME = 'Bitrix\\Crm\\Agent\\Copilot\\CallScriptMaintenanceAgent::run();';

	public static function doRun(): bool
	{
		if (!AIManager::isCallScoringV2Enabled())
		{
			(new self())->setExecutionPeriod(self::WEEK_IN_SECONDS);

			return self::AGENT_CONTINUE;
		}

		if (!AIManager::isAvailable() || !AIManager::isAiCallProcessingEnabled())
		{
			(new self())->setExecutionPeriod(Config::getCyclePeriodSeconds());

			return self::AGENT_CONTINUE;
		}

		$nextDelaySeconds = (new Orchestrator())->tick();
		(new self())->setExecutionPeriod($nextDelaySeconds);

		return self::AGENT_CONTINUE;
	}

	public static function pingAgent(): void
	{
		$agentId = self::getAgentId();
		if ($agentId <= 0)
		{
			return;
		}

		\CAgent::Update($agentId, ['NEXT_EXEC' => (new DateTime())->toString()]);
	}

	private static function getAgentId(): int
	{
		$row = \CAgent::GetList([], ['NAME' => self::AGENT_NAME])->Fetch();

		return (int)($row['ID'] ?? 0);
	}
}

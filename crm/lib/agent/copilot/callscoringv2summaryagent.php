<?php

declare(strict_types=1);

namespace Bitrix\Crm\Agent\Copilot;

use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Settings;
use Bitrix\Crm\Copilot\CallAssessment\Summary\SettingsRepository;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Formatter\SummaryMessageFormatter;
use Bitrix\Crm\Copilot\CallAssessment\Summary\SummaryService;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\Imbot\CallScoringV2SummaryBotAccess;
use Bitrix\Main\Type\DateTime;

final class CallScoringV2SummaryAgent extends AgentBase
{
	public const AGENT_NAME = 'Bitrix\\Crm\\Agent\\Copilot\\CallScoringV2SummaryAgent::run();';
	public const MODULE_ID = 'crm';

	private const AGENT_CONTINUE = true;
	private const FALLBACK_PERIOD_SECONDS = 86400;
	private const DELIVERY_HOUR = 7;

	public static function doRun(): bool
	{
		(new self())->setExecutionPeriod(self::tick());

		return self::AGENT_CONTINUE;
	}

	private static function tick(): int
	{
		if (!AIManager::isCallScoringV2Enabled() || !AIManager::isAiCallProcessingEnabled())
		{
			return self::FALLBACK_PERIOD_SECONDS;
		}

		$settings = (new SettingsRepository())->load();
		if (
			!$settings->isEnabled
			|| $settings->recipientUserIds === []
			|| $settings->scheduleWeekdays === []
		)
		{
			return self::FALLBACK_PERIOD_SECONDS;
		}

		$todayIso = (int)date('N');
		$isTodayScheduled = in_array($todayIso, $settings->scheduleWeekdays, true);

		if ($isTodayScheduled && (int)date('G') >= self::DELIVERY_HOUR)
		{
			self::deliverSummary($settings);

			return self::secondsUntilNextScheduledRun($settings->scheduleWeekdays, skipToday: true);
		}

		return self::secondsUntilNextScheduledRun($settings->scheduleWeekdays, skipToday: !$isTodayScheduled);
	}

	private static function deliverSummary(Settings $settings): void
	{
		$bot = CallScoringV2SummaryBotAccess::getBotIfAvailable();
		if ($bot === null)
		{
			return;
		}

		$summary = (new SummaryService())->buildSummary($settings);
		if ((int)$summary['totalCount'] <= 0)
		{
			return;
		}

		$bot->broadcast(
			(new SummaryMessageFormatter())->format($summary),
			$bot->buildDefaultKeyboard(),
		);
	}

	/**
	 * @param int[] $weekdays ISO 1..7
	 */
	private static function secondsUntilNextScheduledRun(array $weekdays, bool $skipToday): int
	{
		$now = new DateTime();
		$todayIso = (int)$now->format('N');
		$startOffset = $skipToday ? 1 : 0;

		for ($i = 0; $i < 7; $i++)
		{
			$daysAhead = $startOffset + $i;
			$checkDay = (($todayIso - 1 + $daysAhead) % 7) + 1;
			if (!in_array($checkDay, $weekdays, true))
			{
				continue;
			}

			$next = (clone $now)->setTime(self::DELIVERY_HOUR, 0);
			if ($daysAhead > 0)
			{
				$next->add('+' . $daysAhead . ' days');
			}

			return max(60, $next->getTimestamp() - time());
		}

		return self::FALLBACK_PERIOD_SECONDS;
	}

	public static function register(): void
	{
		$existing = \CAgent::GetList(
			[],
			[
				'NAME' => self::AGENT_NAME,
				'MODULE_ID' => self::MODULE_ID,
			],
		)->Fetch();

		if ($existing)
		{
			return;
		}

		\CAgent::AddAgent(
			self::AGENT_NAME,
			self::MODULE_ID,
			'N',
			self::FALLBACK_PERIOD_SECONDS,
			'',
			'Y',
			\ConvertTimeStamp(time() + \CTimeZone::GetOffset() + 60, 'FULL'),
		);
	}

	public static function unRegister(): void
	{
		\CAgent::RemoveAgent(self::AGENT_NAME, self::MODULE_ID);
	}
}

<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings\ChatChannelSettings;
use Bitrix\Crm\Integration\AI\Operation\Autostart\Slider\AutomationScenarioRegistry;
use CCrmActivityDirection;

final class ScenarioOverrideResolver
{
	private const CALL_TRANSCRIPTION_DEPENDENT_SCENARIOS = [
		AutomationScenarioRegistry::SCENARIO_SUMMARIZE,
		AutomationScenarioRegistry::SCENARIO_FILL_FIELDS,
		AutomationScenarioRegistry::SCENARIO_ANALYZE_COMMUNICATION,
		AutomationScenarioRegistry::SCENARIO_CALL_ASSESSMENT,
	];

	public function __construct(
		private readonly AutomationScenarioRegistry $registry = new AutomationScenarioRegistry(),
		private readonly CallAssessmentDefault $callAssessmentDefault = new CallAssessmentDefault(),
	)
	{
	}

	public function hasScenarioOverride(
		FillFieldsSettings $settings,
		string $scenarioCode,
		string $channelCode,
	): bool
	{
		if ($this->isCallAssessmentGatedOff($scenarioCode))
		{
			return false;
		}

		return is_array($settings->getScenarioOverride($scenarioCode, $channelCode));
	}

	public function isScenarioActive(
		FillFieldsSettings $settings,
		string $scenarioCode,
		string $channelCode,
		int $direction,
	): bool
	{
		if ($this->isCallAssessmentGatedOff($scenarioCode))
		{
			return false;
		}

		$override = $settings->getScenarioOverride($scenarioCode, $channelCode);
		if (is_array($override))
		{
			if (!($override['enabled'] ?? false))
			{
				return false;
			}

			if ($channelCode === AutomationScenarioRegistry::CHANNEL_CALL)
			{
				return $this->callModeAllowsDirection((string)($override['mode'] ?? ''), $direction);
			}

			if ($channelCode === AutomationScenarioRegistry::CHANNEL_EMAIL)
			{
				return $this->emailModeAllowsDirection((string)($override['mode'] ?? ''), $direction);
			}

			return true;
		}

		if ($channelCode === AutomationScenarioRegistry::CHANNEL_EMAIL)
		{
			return $this->emailModeAllowsDirection($this->registry->getDefaultMode($channelCode), $direction);
		}

		if (
			$channelCode === AutomationScenarioRegistry::CHANNEL_CALL
			&& $scenarioCode === AutomationScenarioRegistry::SCENARIO_CALL_ASSESSMENT
		)
		{
			$default = $this->activeCallAssessmentDefault();

			return $default !== null
				&& $this->callModeAllowsDirection((string)($default['mode'] ?? ''), $direction);
		}

		return $this->legacyShouldAutostart($settings, $scenarioCode, $channelCode, $direction);
	}

	public function isScenarioStepActive(
		FillFieldsSettings $settings,
		string $scenarioCode,
		string $channelCode,
		int $direction,
	): bool
	{
		if (
			$scenarioCode === AutomationScenarioRegistry::SCENARIO_SUMMARIZE
			&& $this->isScenarioActive(
				$settings,
				AutomationScenarioRegistry::SCENARIO_FILL_FIELDS,
				$channelCode,
				$direction,
			)
		)
		{
			return true;
		}

		return $this->isScenarioActive($settings, $scenarioCode, $channelCode, $direction);
	}

	public function isScenarioEnabledByOverride(
		FillFieldsSettings $settings,
		string $scenarioCode,
		string $channelCode,
		int $direction,
	): bool
	{
		if ($this->isCallAssessmentGatedOff($scenarioCode))
		{
			return false;
		}

		$override = $settings->getScenarioOverride($scenarioCode, $channelCode);
		if (!is_array($override) || !($override['enabled'] ?? false))
		{
			return false;
		}

		if ($channelCode === AutomationScenarioRegistry::CHANNEL_CALL)
		{
			return $this->callModeAllowsDirection((string)($override['mode'] ?? ''), $direction);
		}

		if ($channelCode === AutomationScenarioRegistry::CHANNEL_EMAIL)
		{
			return $this->emailModeAllowsDirection((string)($override['mode'] ?? ''), $direction);
		}

		return true;
	}

	public function isCallPrerequisiteActive(
		FillFieldsSettings $settings,
		string $scenarioCode,
		string $channelCode,
		int $direction,
	): bool
	{
		if (
			$channelCode !== AutomationScenarioRegistry::CHANNEL_CALL
			|| !in_array($scenarioCode, self::CALL_TRANSCRIPTION_DEPENDENT_SCENARIOS, true)
		)
		{
			return true;
		}

		return $this->isScenarioActive(
			$settings,
			AutomationScenarioRegistry::SCENARIO_TRANSCRIPTION,
			AutomationScenarioRegistry::CHANNEL_CALL,
			$direction,
		);
	}

	public function isFirstOnlyMode(
		FillFieldsSettings $settings,
		string $scenarioCode,
		string $channelCode,
	): bool
	{
		if ($this->isCallAssessmentGatedOff($scenarioCode))
		{
			return false;
		}

		$override = $settings->getScenarioOverride($scenarioCode, $channelCode);
		if (is_array($override))
		{
			if (!($override['enabled'] ?? false))
			{
				return false;
			}

			return in_array($override['mode'] ?? '', ['firstIncoming', 'firstChat'], true);
		}

		if (
			$channelCode === AutomationScenarioRegistry::CHANNEL_CALL
			&& $scenarioCode === AutomationScenarioRegistry::SCENARIO_CALL_ASSESSMENT
		)
		{
			$default = $this->activeCallAssessmentDefault();

			return $default !== null
				&& in_array($default['mode'] ?? '', ['firstIncoming', 'firstChat'], true);
		}

		if ($channelCode === AutomationScenarioRegistry::CHANNEL_CALL)
		{
			return $settings->isAutostartTranscriptionOnlyOnFirstCallWithRecording();
		}

		if ($channelCode === AutomationScenarioRegistry::CHANNEL_EMAIL)
		{
			return in_array($this->registry->getDefaultMode($channelCode), ['firstIncoming', 'firstChat'], true);
		}

		$channel = $settings->getChannelSettings($channelCode);

		return $channel instanceof ChatChannelSettings && $channel->isAutostartOnlyFirstChat();
	}

	public function isAnyScenarioActive(
		FillFieldsSettings $settings,
		string $channelCode,
		int $direction,
	): bool
	{
		foreach ($this->registry->getAll() as $scenarioCode => $scenario)
		{
			if (!in_array($channelCode, $scenario['channels'], true))
			{
				continue;
			}

			if ($this->isScenarioActive($settings, $scenarioCode, $channelCode, $direction))
			{
				return true;
			}
		}

		return false;
	}

	private function legacyShouldAutostart(
		FillFieldsSettings $settings,
		string $scenarioCode,
		string $channelCode,
		int $direction,
	): bool
	{
		$operationType = $this->registry->getOperationType($scenarioCode);
		if ($operationType === null)
		{
			return false;
		}

		$channel = $settings->getChannelSettings($channelCode);
		if ($channel === null)
		{
			return false;
		}

		return $channel->shouldAutostart($operationType, [
			'callDirection' => $direction,
			'checkAutomaticProcessingParams' => false,
		]);
	}

	private function isCallAssessmentGatedOff(string $scenarioCode): bool
	{
		return $scenarioCode === AutomationScenarioRegistry::SCENARIO_CALL_ASSESSMENT
			&& !AIManager::isCallScoringV2Enabled();
	}

	private function activeCallAssessmentDefault(): ?array
	{
		if (!AIManager::isCallScoringV2Enabled())
		{
			return null;
		}

		$default = $this->callAssessmentDefault->get();
		if (!is_array($default) || !($default['enabled'] ?? false))
		{
			return null;
		}

		return $default;
	}

	private function callModeAllowsDirection(string $mode, int $direction): bool
	{
		return match ($mode) {
			'firstIncoming', 'allIncoming' => $direction === CCrmActivityDirection::Incoming,
			'outgoing' => $direction === CCrmActivityDirection::Outgoing,
			'both' => in_array($direction, [CCrmActivityDirection::Incoming, CCrmActivityDirection::Outgoing], true),
			default => false,
		};
	}

	private function emailModeAllowsDirection(string $mode, int $direction): bool
	{
		return match ($mode) {
			'firstIncoming', 'allIncoming' => $direction === CCrmActivityDirection::Incoming,
			'allOutgoing' => $direction === CCrmActivityDirection::Outgoing,
			'all' => in_array($direction, [CCrmActivityDirection::Incoming, CCrmActivityDirection::Outgoing], true),
			default => false,
		};
	}
}

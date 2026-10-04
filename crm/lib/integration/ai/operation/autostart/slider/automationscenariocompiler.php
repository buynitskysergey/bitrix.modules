<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\Slider;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class AutomationScenarioCompiler
{
	public function __construct(
		private readonly AutomationScenarioRegistry $registry = new AutomationScenarioRegistry(),
	)
	{
	}

	public function normalize(array $scenarioUpdates): Result
	{
		foreach ($scenarioUpdates as $scenarioIndex => $update)
		{
			if (!is_array($update))
			{
				return (new Result())->addError(new Error('invalid scenario code', 'invalid_scenario_code'));
			}

			$scenarioCode = (string)($update['code'] ?? '');
			if (!$this->registry->has($scenarioCode) || $this->isScenarioGatedOff($scenarioCode))
			{
				return (new Result())->addError(new Error('invalid scenario code', 'invalid_scenario_code'));
			}

			$channels = $update['channels'] ?? [];
			if (!is_array($channels) || $channels === [])
			{
				return (new Result())->addError(new Error('invalid channel code', 'invalid_channel_code'));
			}

			foreach ($channels as $channelIndex => $channelUpdate)
			{
				if (!is_array($channelUpdate))
				{
					return (new Result())->addError(new Error('invalid channel code', 'invalid_channel_code'));
				}

				$channelCode = (string)($channelUpdate['code'] ?? '');
				if (!$this->registry->supportsChannel($scenarioCode, $channelCode))
				{
					return (new Result())->addError(new Error('invalid channel code', 'invalid_channel_code'));
				}

				if (!array_key_exists('enabled', $channelUpdate) || !is_bool($channelUpdate['enabled']))
				{
					return (new Result())->addError(new Error('invalid channel state', 'invalid_channel_state'));
				}

				$mode = (string)($channelUpdate['mode'] ?? '');
				$availableModes = $this->registry->getAvailableModes($channelCode);
				if (!$channelUpdate['enabled'] && !in_array($mode, $availableModes, true))
				{
					$mode = $this->registry->getDefaultMode($channelCode);
					$scenarioUpdates[$scenarioIndex]['channels'][$channelIndex]['mode'] = $mode;
				}

				if (!in_array($mode, $availableModes, true))
				{
					return (new Result())->addError(new Error('invalid channel mode', 'invalid_channel_mode'));
				}
			}
		}

		return (new Result())->setData(['scenarioUpdates' => $scenarioUpdates]);
	}

	public function apply(FillFieldsSettings $current, array $scenarioUpdates): FillFieldsSettings
	{
		$normalized = $this->normalize($scenarioUpdates);
		if (!$normalized->isSuccess())
		{
			throw new ArgumentException($normalized->getError()->getMessage(), 'scenarioUpdates');
		}

		$overrides = array_intersect_key(
			$current->getScenarioOverrides(),
			$this->registry->getAll(),
		);

		foreach ($normalized->getData()['scenarioUpdates'] as $update)
		{
			$code = (string)$update['code'];
			$overrides[$code] ??= [];

			foreach ($update['channels'] as $channelUpdate)
			{
				$overrides[$code][(string)$channelUpdate['code']] = [
					'enabled' => (bool)$channelUpdate['enabled'],
					'mode' => (string)$channelUpdate['mode'],
				];
			}
		}

		return $current->withScenarioOverrides($overrides);
	}

	private function isScenarioGatedOff(string $scenarioCode): bool
	{
		return $scenarioCode === AutomationScenarioRegistry::SCENARIO_CALL_ASSESSMENT
			&& !AIManager::isCallScoringV2Enabled();
	}
}

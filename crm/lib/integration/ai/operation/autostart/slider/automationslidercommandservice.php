<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\Slider;

use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final readonly class AutomationSliderCommandService
{
	private const LOCK_TIMEOUT = 2;
	private const CALL_TRANSCRIPTION_DEPENDENT_SCENARIOS = [
		AutomationScenarioRegistry::SCENARIO_SUMMARIZE,
		AutomationScenarioRegistry::SCENARIO_FILL_FIELDS,
		AutomationScenarioRegistry::SCENARIO_ANALYZE_COMMUNICATION,
	];

	private AutomationSliderLock $lock;

	public function __construct(
		private AutostartSettingsRepository $repository,
		private AutomationScenarioCompiler $compiler,
		?AutomationSliderLock $lock = null,
	)
	{
		$this->lock = $lock ?? new AutomationSliderLock();
	}

	public function save(array $scope, string $revision, array $scenarioUpdates): Result
	{
		$currentRevision = $this->repository->getRevision($scope);
		if ($currentRevision !== $revision)
		{
			return (new Result())->addError(
				new Error('slider state conflict', 'slider_state_conflict')
			);
		}

		$normalized = $this->compiler->normalize($scenarioUpdates);
		if (!$normalized->isSuccess())
		{
			return (new Result())->addErrors($normalized->getErrors());
		}

		$scenarioUpdates = $normalized->getData()['scenarioUpdates'];
		$lockName = $this->getLockName($scope);
		if (!$this->lock->lock($lockName, self::LOCK_TIMEOUT))
		{
			return (new Result())->addError(
				new Error('slider is busy', 'slider_busy')
			);
		}

		try
		{
			if ($this->repository->getFreshRevision($scope) !== $revision)
			{
				return (new Result())->addError(
					new Error('slider state conflict', 'slider_state_conflict')
				);
			}

			$current = $this->repository->get($scope);
			$scenarioUpdates = $this->disableDependentCallScenariosWithoutTranscription($scenarioUpdates, $current);
			$compiled = $this->compiler->apply($current, $scenarioUpdates);

			return $this->repository->save($compiled, $scope);
		}
		finally
		{
			$this->lock->unlock($lockName);
		}
	}

	private function disableDependentCallScenariosWithoutTranscription(
		array $scenarioUpdates,
		FillFieldsSettings $current,
	): array
	{
		if (!$this->isTranscriptionCallDisabled($scenarioUpdates, $current))
		{
			return $scenarioUpdates;
		}

		foreach ($scenarioUpdates as $scenarioIndex => $update)
		{
			$scenarioCode = (string)($update['code'] ?? '');
			if (!in_array($scenarioCode, self::CALL_TRANSCRIPTION_DEPENDENT_SCENARIOS, true))
			{
				continue;
			}

			foreach (($update['channels'] ?? []) as $channelIndex => $channelUpdate)
			{
				if (($channelUpdate['code'] ?? '') === AutomationScenarioRegistry::CHANNEL_CALL)
				{
					$scenarioUpdates[$scenarioIndex]['channels'][$channelIndex]['enabled'] = false;
				}
			}
		}

		return $scenarioUpdates;
	}

	private function isTranscriptionCallDisabled(array $scenarioUpdates, FillFieldsSettings $current): bool
	{
		foreach ($scenarioUpdates as $update)
		{
			if (($update['code'] ?? '') !== AutomationScenarioRegistry::SCENARIO_TRANSCRIPTION)
			{
				continue;
			}

			foreach (($update['channels'] ?? []) as $channelUpdate)
			{
				if (($channelUpdate['code'] ?? '') === AutomationScenarioRegistry::CHANNEL_CALL)
				{
					return !($channelUpdate['enabled'] ?? false);
				}
			}
		}

		$storedOverride = $current->getScenarioOverride(
			AutomationScenarioRegistry::SCENARIO_TRANSCRIPTION,
			AutomationScenarioRegistry::CHANNEL_CALL,
		);
		if (is_array($storedOverride))
		{
			return !($storedOverride['enabled'] ?? false);
		}

		return false;
	}

	private function getLockName(array $scope): string
	{
		return sprintf('crm_ai_automation_slider_%d_%s', $scope['entityTypeId'], $scope['categoryId'] ?? 'null');
	}
}

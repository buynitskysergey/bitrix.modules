<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline\Scenario;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\SelectCallScoreScript;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;

final class SelectCallScoringScriptScenario extends AbstractScenario
{
	public function getId(): string
	{
		return Scenario::SELECT_CALL_SCORING_SCRIPT_SCENARIO;
	}

	public function getSteps(): array
	{
		return [
			TranscribeCallRecording::class,
			SelectCallScoreScript::class,
		];
	}

	public function getStepsWithSkipTranscription(): array
	{
		return [
			SelectCallScoreScript::class,
		];
	}

	public function canSkipTranscription(?string $activityProvider): bool
	{
		return $activityProvider !== null && !Scenario::isScenarioRequiresTranscription($activityProvider);
	}

	public function isEnabled(): bool
	{
		return AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment);
	}

	public function getDisabledSliderCode(): ?string
	{
		return Scenario::CALL_SCORING_SCENARIO_SLIDER_CODE;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline\Scenario;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\ScoreCallV2;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;

final class CallScoringScenarioV2 extends AbstractScenario
{
	public function getId(): string
	{
		return Scenario::CALL_SCORING_V2_SCENARIO;
	}

	public function getSteps(): array
	{
		return [
			TranscribeCallRecording::class,
			SummarizeCallTranscription::class,
			ScoreCallV2::class,
		];
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

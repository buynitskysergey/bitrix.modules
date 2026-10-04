<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart;

use Bitrix\Crm\Copilot\CallAssessment\Enum\AutoCheckType;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\BaasManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\ScoreCallV2;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;

final class ScoreCallSettings implements AutoStartInterface
{
	private const AUTOSTART_OPERATION_TYPES = [
		TranscribeCallRecording::TYPE_ID,
		ScoreCall::TYPE_ID,
		ScoreCallV2::TYPE_ID,
	];

	public function __construct(private readonly int $autoCheckType)
	{
	}

	public function shouldAutostart(int $operationType, int $callDirection): bool
	{
		if (
			!(
				AIManager::isAiCallAutomaticProcessingAllowed()
				&& AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment)
				&& BaasManager::hasPackage()
			)
		)
		{
			return false;
		}

		if (!in_array($operationType, self::AUTOSTART_OPERATION_TYPES, true))
		{
			return false;
		}

		return AutoCheckType::tryFrom($this->autoCheckType)?->allowsCallDirection($callDirection) ?? false;
	}

	public function isAutostartTranscriptionOnlyOnFirstCallWithRecording(): bool
	{
		return $this->autoCheckType === AutoCheckType::FIRST_INCOMING->value;
	}
}

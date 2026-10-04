<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart;

use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\Enum\AutoCheckType;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;

final class CallAssessmentRuntimePolicy
{
	public function allows(
		?CallAssessmentItem $item,
		int $operationType,
		int $direction,
		bool $automationAllowed = true,
	): bool
	{
		if ($item === null)
		{
			return false;
		}

		if (!$automationAllowed)
		{
			return false;
		}

		if (!in_array($operationType, [TranscribeCallRecording::TYPE_ID, ScoreCall::TYPE_ID], true))
		{
			return false;
		}

		$autoCheckTypeId = $item->getAutoCheckTypeId();
		if ($autoCheckTypeId === null)
		{
			return false;
		}

		return AutoCheckType::tryFrom($autoCheckTypeId)?->allowsCallDirection($direction) ?? false;
	}

	public function isFirstIncomingOnly(?CallAssessmentItem $item): bool
	{
		return $item?->getAutoCheckTypeId() === AutoCheckType::FIRST_INCOMING->value;
	}
}

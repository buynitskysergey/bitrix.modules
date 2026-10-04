<?php

namespace Bitrix\Crm\Update;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Operation\Autostart\CallAssessmentDefault;

final readonly class CallScoringV2AssessmentDefault
{
	public function __construct(
		private CallAssessmentDefault $default = new CallAssessmentDefault(),
	)
	{
	}

	public function execute(): bool
	{
		if (!AIManager::isCallScoringV2Enabled())
		{
			return false;
		}

		if ($this->default->isStored())
		{
			return false;
		}

		$this->default->computeAndStore();

		return true;
	}
}

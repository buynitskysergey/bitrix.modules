<?php

namespace Bitrix\Crm\Tour;

use Bitrix\Crm\Copilot\CallScriptMaintenance\FirstScriptCreatedFlag;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Service\Container;

class CallScoringV2 extends Base
{
	public const OPTION_NAME = 'aha-moment-call-scoring-v2';

	public function onReset(): void
	{
		(new FirstScriptCreatedFlag())->reset();
	}

	protected function canShow(): bool
	{
		if (!AIManager::isCallScoringV2Enabled())
		{
			return false;
		}

		$flag = new FirstScriptCreatedFlag();
		if (!$flag->isReached())
		{
			return false;
		}

		if (!Container::getInstance()->getUserPermissions()->copilotCallAssessment()->canRead())
		{
			return false;
		}

		return !$this->isUserSeenTour();
	}

	protected function getComponentTemplate(): string
	{
		return 'call_scoring_v2';
	}

	protected function getOptions(): array
	{
		$flag = new FirstScriptCreatedFlag();

		return [
			'optionCategory' => $this->getOptionCategory(),
			'optionName' => $this->getOptionName(),
			'scriptId' => $flag->getScriptId(),
			'kind' => $flag->getKind(),
		];
	}
}

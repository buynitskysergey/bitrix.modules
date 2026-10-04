<?php

namespace Bitrix\Crm\Tour;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Service\Container;

class CallScoringV2Timeline extends Base
{
	public const OPTION_NAME = 'aha-moment-call-scoring-v2-timeline';

	protected function canShow(): bool
	{
		if (!AIManager::isCallScoringV2Enabled())
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
		return 'call_scoring_v2_timeline';
	}

	protected function getOptions(): array
	{
		return [
			'optionCategory' => $this->getOptionCategory(),
			'optionName' => $this->getOptionName(),
		];
	}
}

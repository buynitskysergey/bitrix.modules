<?php

namespace Bitrix\Crm\Tour;

use Bitrix\Crm\Integration\AI\AIManager;

class CallScoringV2Promo extends Base
{
	public const OPTION_NAME = 'aha-moment-call-scoring-v2-promo';

	protected function canShow(): bool
	{
		if (!AIManager::isCallScoringV2Enabled())
		{
			return false;
		}

		return !$this->isUserSeenTour();
	}

	protected function getComponentTemplate(): string
	{
		return 'call_scoring_v2_promo';
	}

	protected function getOptions(): array
	{
		return [
			'optionCategory' => $this->getOptionCategory(),
			'optionName' => $this->getOptionName(),
		];
	}
}

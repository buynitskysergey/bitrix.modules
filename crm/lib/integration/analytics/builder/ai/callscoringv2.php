<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Analytics\Builder\AI;

use Bitrix\Crm\Integration\Analytics\Dictionary;

final class CallScoringV2 extends AIBaseEvent
{
	protected function getEvent(): string
	{
		return Dictionary::EVENT_CALL_SCORING;
	}
}

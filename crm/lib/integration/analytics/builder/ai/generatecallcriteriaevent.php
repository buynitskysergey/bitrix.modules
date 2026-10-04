<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Analytics\Builder\AI;

use Bitrix\Crm\Integration\Analytics\Dictionary;

final class GenerateCallCriteriaEvent extends AIBaseEvent
{
	protected function getEvent(): string
	{
		return Dictionary::EVENT_GENERATE_CALL_CRITERIA;
	}
}

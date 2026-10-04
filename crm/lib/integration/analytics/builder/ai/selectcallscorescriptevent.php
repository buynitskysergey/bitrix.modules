<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\AI;

use Bitrix\Crm\Integration\Analytics\Dictionary;

final class SelectCallScoreScriptEvent extends AIBaseEvent
{
	protected function getEvent(): string
	{
		return Dictionary::EVENT_SELECT_CALL_SCORE_SCRIPT;
	}
}

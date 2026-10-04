<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\AI;

use Bitrix\Crm\Integration\Analytics\Dictionary;

final class GenerateCallScriptFromDialogEvent extends AIBaseEvent
{
	protected function getEvent(): string
	{
		return Dictionary::EVENT_GENERATE_CALL_SCRIPT_FROM_DIALOG;
	}
}
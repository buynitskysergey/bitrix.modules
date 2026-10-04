<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Imbot;

use Bitrix\Main\Loader;

final class CallScoringV2SummaryBotAccess
{
	public static function getBotIfAvailable(): ?CallScoringV2SummaryBot
	{
		return self::isAvailable() ? new CallScoringV2SummaryBot() : null;
	}

	public static function isAvailable(): bool
	{
		return Loader::includeModule('imbot');
	}
}

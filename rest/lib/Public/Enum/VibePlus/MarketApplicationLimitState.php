<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Enum\VibePlus;

enum MarketApplicationLimitState: string
{
	case Finite = 'finite';
	case Unlimited = 'unlimited';
	case NotApplicable = 'notApplicable';
	case Unknown = 'unknown';
}

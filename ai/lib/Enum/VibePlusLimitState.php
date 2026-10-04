<?php declare(strict_types=1);

namespace Bitrix\AI\Enum;

enum VibePlusLimitState
{
	case NotApplicable;
	case TechnicalLimit;
	case BuyWithDemo;
	case BuyWithoutDemo;
}

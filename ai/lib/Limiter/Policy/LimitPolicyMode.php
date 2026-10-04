<?php

declare(strict_types=1);

namespace Bitrix\AI\Limiter\Policy;

enum LimitPolicyMode: string
{
	case Legacy = 'legacy';
	case Transition = 'transition';
	case Unlimited = 'unlimited';
	case MonthlyPool = 'monthly_pool';
	case DailyOnly = 'daily_only';
}

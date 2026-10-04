<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary;

enum Weekday: int
{
	case Monday = 1;
	case Tuesday = 2;
	case Wednesday = 3;
	case Thursday = 4;
	case Friday = 5;
	case Saturday = 6;
	case Sunday = 7;
}

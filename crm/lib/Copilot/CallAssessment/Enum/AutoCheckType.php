<?php

namespace Bitrix\Crm\Copilot\CallAssessment\Enum;

use CCrmActivityDirection;

enum AutoCheckType: int
{
	case DISABLED = 0;
	case FIRST_INCOMING = 1;
	case INCOMING = 2;
	case OUTGOING = 3;
	case ALL = 4;

	public function allowsCallDirection(int $direction): bool
	{
		return match ($this) {
			self::DISABLED => false,
			self::FIRST_INCOMING, self::INCOMING => $direction === CCrmActivityDirection::Incoming,
			self::OUTGOING => $direction === CCrmActivityDirection::Outgoing,
			self::ALL => in_array($direction, [CCrmActivityDirection::Incoming, CCrmActivityDirection::Outgoing], true),
		};
	}
}

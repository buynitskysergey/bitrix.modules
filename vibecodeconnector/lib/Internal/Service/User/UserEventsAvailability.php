<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\User;

use Bitrix\Main\Config\Feature;
use Bitrix\Vibecodeconnector\Internal\Config\Feature\UserEvents;

class UserEventsAvailability
{
	public function isEnabled(): bool
	{
		return Feature::isEnabled(UserEvents::class);
	}
}

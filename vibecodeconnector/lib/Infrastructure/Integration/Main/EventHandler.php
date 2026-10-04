<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Integration\Main;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;

final class EventHandler
{
	public static function onEpilog(): void
	{
		if (!Loader::includeModule('vibecodeconnector'))
		{
			return;
		}

		$isAdminSection = defined('ADMIN_SECTION') && ADMIN_SECTION === true;
		if (!$isAdminSection)
		{
			$currentUserId = (int)CurrentUser::get()->getId();
			$availabilityService = new AvailabilityService();
			if ($currentUserId <= 0 || !$availabilityService->isEnabled())
			{
				return;
			}
		}

		Extension::load('vibecodeconnector.im-button-binder');
	}
}

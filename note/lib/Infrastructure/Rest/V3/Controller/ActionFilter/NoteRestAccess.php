<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Controller\ActionFilter;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Note\Internal\Access\AccessController;
use Bitrix\Note\Internal\Access\ActionDictionary;
use Bitrix\Note\Internal\Service\License\LicenseService;
use Bitrix\Rest\V3\Exception\AccessDeniedException;

class NoteRestAccess extends Base
{
	public function onBeforeAction(Event $event): EventResult
	{
		// Tariff/tool gate before ACL: same denial as an ACL failure.
		if ($this->createLicenseService()->isAccessBlocked())
		{
			throw new AccessDeniedException();
		}

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_NOTE_ACCESS))
		{
			throw new AccessDeniedException();
		}

		return new EventResult(EventResult::SUCCESS, null, null, $this);
	}

	protected function createLicenseService(): LicenseService
	{
		return new LicenseService();
	}
}

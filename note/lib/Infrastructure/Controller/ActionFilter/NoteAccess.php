<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller\ActionFilter;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Access\AccessController;
use Bitrix\Note\Internal\Access\ActionDictionary;
use Bitrix\Note\Internal\Service\License\LicenseService;

class NoteAccess extends Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		// Tariff/tool gate before ACL: block every engine action uniformly when the
		// module feature is unavailable or the Notes tool is turned off.
		if ($this->createLicenseService()->isAccessBlocked())
		{
			return $this->denied();
		}

		if (AccessController::getCurrent()->check(ActionDictionary::ACTION_NOTE_ACCESS))
		{
			return null;
		}

		return $this->denied();
	}

	protected function createLicenseService(): LicenseService
	{
		return new LicenseService();
	}

	private function denied(): EventResult
	{
		$this->addError(new Error(Loc::getMessage('NOTE_ACCESS_DENIED')));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}
}

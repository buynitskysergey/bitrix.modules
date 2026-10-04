<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Access\Permission;

use Bitrix\Note\Internal\Access\AccessController;
use Bitrix\Note\Internal\Access\ActionDictionary;
use Bitrix\Note\Internal\Service\License\LicenseService;

// Not final: unit tests subclass to override the LicenseService seam.
class Permission extends \Bitrix\UI\AccessRights\V2\Permission
{
	public function canUpdate(): bool
	{
		// Tariff/tool gate before ACL: same denial as an ACL failure.
		if ($this->createLicenseService()->isAccessBlocked())
		{
			return false;
		}

		return AccessController::getInstance($this->userId)->check(ActionDictionary::ACTION_NOTE_ACCESS);
	}

	protected function createLicenseService(): LicenseService
	{
		return new LicenseService();
	}
}

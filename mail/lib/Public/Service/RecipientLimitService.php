<?php

namespace Bitrix\Mail\Public\Service;

use Bitrix\Mail\Helper\LicenseManager;

class RecipientLimitService
{
	/**
	 * @return array{total: int, perField: int}
	 */
	public static function getRecipientLimits(): array
	{
		return [
			'total' => LicenseManager::getMessageRecipientsTotalLimit(),
			'perField' => LicenseManager::getEmailsLimitToSendMessage(),
		];
	}
}

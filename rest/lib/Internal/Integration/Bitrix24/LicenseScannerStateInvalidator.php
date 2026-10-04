<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\LicenseScanner\Manager;
use Bitrix\Main\Loader;

final class LicenseScannerStateInvalidator
{
	public static function reset(): void
	{
		try
		{
			if (
				!Loader::includeModule('bitrix24')
				|| !class_exists(Manager::class)
				|| !method_exists(Manager::class, 'resetComputedState')
			)
			{
				return;
			}

			Manager::getInstance()->resetComputedState();
		}
		catch (\Throwable)
		{
			return;
		}
	}
}

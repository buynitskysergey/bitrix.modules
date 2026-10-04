<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Immobile;

use Bitrix\Main\Config\Option;

/**
 * Whether the Vibe button is available in the messenger, stored in an option owned by the
 * immobile module. This module mirrors the portal toggle into it, the owner reads it on its own.
 *
 * The option is written straight to the platform and never memoized here. This is the sanctioned
 * exception from reaching the options only through Internal\Config\ModuleOptions: that access point
 * serves this module alone.
 */
final class VibeButtonAvailability
{
	private const MODULE_ID = 'immobile';
	private const OPTION_NAME = 'is_vibecode_button_available';
	private const GLOBAL_SITE_ID = '';
	private const YES = 'Y';
	private const NO = 'N';

	public function set(bool $value): void
	{
		Option::set(self::MODULE_ID, self::OPTION_NAME, $value ? self::YES : self::NO, self::GLOBAL_SITE_ID);
	}
}

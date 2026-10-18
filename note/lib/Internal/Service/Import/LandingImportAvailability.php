<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import;

use Bitrix\Main\Config\Option;

class LandingImportAvailability
{
	public const OPTION_LANDING_IMPORT = 'landing_import_enabled';

	private const DEFAULT_VALUE = 'N';

	public function isEnabled(): bool
	{
		return Option::get('note', self::OPTION_LANDING_IMPORT, self::DEFAULT_VALUE) === 'Y';
	}
}

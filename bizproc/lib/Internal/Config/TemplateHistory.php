<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Config;

use Bitrix\Main\Config\Option;

/**
 * The only place where the template version history options are read: recording, rotation and the
 * history surfaces are all derived from these getters.
 */
class TemplateHistory
{
	private const MODULE_ID = 'bizproc';

	private const ENABLED_OPTION = 'template_version_history_enabled';
	private const VERSION_LIMIT_OPTION = 'template_version_limit';

	private const DEFAULT_VERSION_LIMIT = 10;

	public static function isEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::ENABLED_OPTION, 'N') === 'Y';
	}

	/**
	 * How many published versions keep their body; the rest stay in the journal as events only.
	 */
	public static function getVersionLimit(): int
	{
		$stored = Option::get(self::MODULE_ID, self::VERSION_LIMIT_OPTION, (string)self::DEFAULT_VERSION_LIMIT);
		if (!is_numeric($stored))
		{
			return self::DEFAULT_VERSION_LIMIT;
		}

		$value = (int)$stored;

		return $value > 0 ? $value : self::DEFAULT_VERSION_LIMIT;
	}
}

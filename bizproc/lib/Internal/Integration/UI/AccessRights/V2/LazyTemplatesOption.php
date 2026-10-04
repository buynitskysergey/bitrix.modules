<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2;

use Bitrix\Main\Config\Option;

final class LazyTemplatesOption
{
	public const MODULE_ID = 'bizproc';
	public const NAME = 'access_rights_lazy_templates';
	public const VALUE_ENABLED = 'Y';

	public static function isEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::NAME, self::VALUE_ENABLED) === self::VALUE_ENABLED;
	}
}

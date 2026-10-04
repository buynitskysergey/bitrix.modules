<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Config;

final class InternalDraftCompatibility
{
	private const MIN_MAIN_VERSION = '26.800.0';

	public static function isMainVersionSupported(mixed $version): bool
	{
		return is_string($version)
			&& $version !== ''
			&& version_compare($version, self::MIN_MAIN_VERSION, '>=')
		;
	}
}

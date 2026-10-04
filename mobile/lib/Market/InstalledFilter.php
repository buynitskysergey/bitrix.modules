<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market;

final class InstalledFilter
{
	public const UPDATES = 'updates';

	public static function normalize(string $installedFilter): string
	{
		return trim($installedFilter) === self::UPDATES ? self::UPDATES : '';
	}
}

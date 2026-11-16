<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\History;

use Bitrix\Note\Internal\Entity\History\Source;

final class SourceContext
{
	private static ?Source $source = null;

	public static function replace(?Source $source): void
	{
		self::$source = $source;
	}

	public static function get(): ?Source
	{
		return self::$source;
	}

	public static function reset(): void
	{
		self::$source = null;
	}
}

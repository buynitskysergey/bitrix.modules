<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Exception;

class KeyNotJoinableException extends KeyTypeMismatchException
{
	public static function forKey(string $key, string $type): self
	{
		return new self(sprintf(
			'DataView join key "%s" (%s) is not joinable: multiple fields cannot be used as a join key',
			$key,
			$type,
		));
	}
}

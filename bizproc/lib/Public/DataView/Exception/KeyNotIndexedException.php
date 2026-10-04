<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Exception;

class KeyNotIndexedException extends KeyNotJoinableException
{
	public static function forKey(string $key, string $type): self
	{
		return new self(sprintf(
			'DataView join key "%s" (%s) is not index-backed: only a primary key or a reference field can be a join key',
			$key,
			$type,
		));
	}
}

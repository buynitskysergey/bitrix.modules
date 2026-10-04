<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Exception;

class KeyTypeMismatchException extends \Exception
{
	public const ERROR_CODE = 'ERR-001';

	public static function forKeys(string $leftKey, string $leftType, string $rightKey, string $rightType): self
	{
		return new self(sprintf(
			'DataView join key types are incompatible: %s (%s) vs %s (%s)',
			$leftKey,
			$leftType,
			$rightKey,
			$rightType,
		));
	}
}

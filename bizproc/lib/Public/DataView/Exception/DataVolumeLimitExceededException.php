<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Exception;

/**
 * Extends {@see RowLimitExceededException} so every consumer that already maps the row limit
 * to a "too much data" error handles the byte budget the same way.
 */
class DataVolumeLimitExceededException extends RowLimitExceededException
{
	public const ERROR_CODE = 'ERR-005';

	public static function bytes(int $byteLimit): self
	{
		return new self(sprintf('DataView combine data volume exceeds the limit of %d bytes', $byteLimit));
	}
}

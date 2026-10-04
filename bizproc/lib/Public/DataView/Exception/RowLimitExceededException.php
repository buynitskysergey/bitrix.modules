<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Exception;

class RowLimitExceededException extends \Exception
{
	public const ERROR_CODE = 'ERR-002';

	public static function limit(int $limit): self
	{
		return new self(sprintf('DataView combine result exceeds the row limit of %d', $limit));
	}

	public static function aggregateInputLimit(int $limit): self
	{
		return new self(sprintf('DataView aggregate input exceeds the row limit of %d', $limit));
	}
}

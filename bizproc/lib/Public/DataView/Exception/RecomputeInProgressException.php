<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Exception;

class RecomputeInProgressException extends \Exception
{
	public static function forStorageType(int $storageTypeId): self
	{
		return new self(sprintf('DataView recompute is already in progress for storage type %d', $storageTypeId));
	}

	public static function forCatalog(): self
	{
		return new self('DataView catalog is locked by another save');
	}
}

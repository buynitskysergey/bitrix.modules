<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Storage;

final readonly class DiskSpaceLimit
{
	public function __construct(
		public int $targetBytes,
		public int $usedBytes,
	)
	{
	}
}

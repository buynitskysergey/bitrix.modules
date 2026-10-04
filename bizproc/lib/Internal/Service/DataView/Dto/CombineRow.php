<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView\Dto;

use Bitrix\Bizproc\Internal\Entity\DataView\RowIdentity;

final class CombineRow
{
	public function __construct(
		public readonly int $leftRecordId,
		public readonly int $rightRecordId,
		public readonly array $values,
	) {
	}

	public function getIdentity(): RowIdentity
	{
		return new RowIdentity($this->leftRecordId, $this->rightRecordId);
	}
}

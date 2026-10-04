<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Dto;

final class ResolvedStampConstant
{
	public function __construct(
		public readonly string $title,
		public readonly string $type,
		public readonly mixed $value,
	) {
	}
}

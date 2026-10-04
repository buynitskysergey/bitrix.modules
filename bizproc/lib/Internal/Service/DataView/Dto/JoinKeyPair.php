<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView\Dto;

final class JoinKeyPair
{
	public function __construct(
		public readonly string $left,
		public readonly string $right,
	) {
	}
}

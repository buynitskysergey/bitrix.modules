<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView\Dto;

final class PlanColumn
{
	private function __construct(
		public readonly string $code,
		public readonly bool $isStamp,
		public readonly ?string $alias,
		public readonly ?string $field,
		public readonly mixed $value,
	) {
	}

	public static function source(string $code, string $field, ?string $alias = null): self
	{
		return new self($code, false, $alias, $field, null);
	}

	public static function stamp(string $code, mixed $value): self
	{
		return new self($code, true, null, null, $value);
	}
}

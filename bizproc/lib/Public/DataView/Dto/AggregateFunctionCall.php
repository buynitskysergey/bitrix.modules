<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Dto;

final class AggregateFunctionCall
{
	public function __construct(
		public readonly string $column,
		public readonly AggregateFunction $fn,
		public readonly string $code,
	) {
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView\Dto;

use Bitrix\Bizproc\Public\DataView\Dto\AggregateFunctionCall;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;

final class AggregatePlan
{
	/**
	 * @param string[] $groupBy
	 * @param AggregateFunctionCall[] $functions
	 * @param PlanColumn[] $stamps
	 */
	public function __construct(
		public readonly SourceRef $ref,
		public readonly array $groupBy,
		public readonly array $functions,
		public readonly array $stamps,
	) {
	}

	/**
	 * @return array<string, mixed> stamp values indexed by column code
	 */
	public function stampValues(): array
	{
		$values = [];
		foreach ($this->stamps as $stamp)
		{
			$values[$stamp->code] = $stamp->value;
		}

		return $values;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView\Dto;

final class JoinPlan
{
	/**
	 * @param JoinKeyPair[] $keyPairs
	 * @param PlanColumn[] $columns
	 */
	public function __construct(
		public readonly string $leftAlias,
		public readonly string $rightAlias,
		public readonly JoinPlanSide $leading,
		public readonly JoinPlanSide $other,
		public readonly array $keyPairs,
		public readonly array $columns,
	) {
	}

	public function isLeadingLeft(): bool
	{
		return $this->leading->alias === $this->leftAlias;
	}
}

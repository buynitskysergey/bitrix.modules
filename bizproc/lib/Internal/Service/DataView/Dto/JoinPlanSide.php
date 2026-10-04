<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView\Dto;

use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;

final class JoinPlanSide
{
	/**
	 * @param string[] $keyFields
	 */
	public function __construct(
		public readonly SourceRef $ref,
		public readonly string $alias,
		public readonly array $keyFields,
	) {
	}
}

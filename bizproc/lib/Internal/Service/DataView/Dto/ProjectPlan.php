<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView\Dto;

use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;

final class ProjectPlan
{
	/**
	 * @param PlanColumn[] $columns
	 */
	public function __construct(
		public readonly SourceRef $ref,
		public readonly array $columns,
	) {
	}
}

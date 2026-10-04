<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Dto;

final class ScriptStructureDto
{
	/**
	 * @param int[] $clientTypeIds
	 * @param array<int, array{title?: string, description?: string}> $criteria
	 */
	public function __construct(
		public readonly int $callType,
		public readonly array $clientTypeIds,
		public readonly array $criteria,
	) {}
}

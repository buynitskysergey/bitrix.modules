<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary;

/**
 * Immutable render inputs the ManagerSummaryFormatter needs but must not fetch itself
 * (manager display name, script id -> title map, data-window bounds). The operation resolves
 * these from the DB / Container and passes them in, keeping the formatter free of side effects
 * and unit-testable.
 */
final class ManagerSummaryRenderContext
{
	/**
	 * @param array<int, string> $scriptNames script assessment-setting id => title
	 * @param array<int, string> $criterionNames criterion definition id => title
	 */
	public function __construct(
		public readonly int $managerId,
		public readonly string $managerName,
		public readonly array $scriptNames,
		public readonly array $criterionNames,
		public readonly ?int $periodFrom,
		public readonly ?int $periodTo,
	)
	{
	}
}

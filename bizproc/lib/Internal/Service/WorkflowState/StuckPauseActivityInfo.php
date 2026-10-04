<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\WorkflowState;

final class StuckPauseActivityInfo
{
	public function __construct(
		public readonly string $name,
		public readonly string $type,
	)
	{
	}
}

<?php

namespace Bitrix\Bizproc\Starter\Dto;

use Bitrix\Bizproc\Starter\Enum\Face;
use Bitrix\Bizproc\Starter\Enum\ManualStartSurface;

final class ContextDto
{
	/**
	 * @param ManualStartSurface|null $manualStartSurface the surface the caller starts from, set by the
	 *     entry points a manual start of an employee goes through and left null by every automatic one
	 */
	public function __construct(
		public readonly string $moduleId,
		public readonly Face $face = Face::WEB,
		public readonly bool $isManual = false,
		public readonly ?ManualStartSurface $manualStartSurface = null,
	)
	{}
}

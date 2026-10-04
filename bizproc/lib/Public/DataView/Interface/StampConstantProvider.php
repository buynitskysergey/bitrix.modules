<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Interface;

use Bitrix\Bizproc\Public\DataView\Dto\ResolvedStampConstant;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;

interface StampConstantProvider
{
	public function getAvailableStampConstants(int $actorId, ?int $templateId = null): array;

	public function resolveStampConstant(SourceRef $constant): ResolvedStampConstant;
}

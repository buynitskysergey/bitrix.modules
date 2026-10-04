<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService;

final class TemplateVersion
{
	public function __construct(
		public readonly int $id,
		public readonly int $versionNumber,
		public readonly int $publicationType,
		public readonly ?int $createdTimestamp,
		public readonly ?int $authorId,
		public readonly bool $isCurrent,
	)
	{}
}

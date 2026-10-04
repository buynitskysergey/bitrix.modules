<?php

namespace Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService;

final class RestoreVersionRequest
{
	public function __construct(
		public readonly int $templateId,
		public readonly int $versionId,
		public readonly int $userId,
	)
	{}
}

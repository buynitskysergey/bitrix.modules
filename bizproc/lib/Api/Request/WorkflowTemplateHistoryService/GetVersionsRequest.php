<?php

namespace Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService;

final class GetVersionsRequest
{
	public function __construct(
		public readonly int $templateId,
		public readonly ?int $userId = null,
	)
	{}
}

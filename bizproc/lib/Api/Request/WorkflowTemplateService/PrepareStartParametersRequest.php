<?php

namespace Bitrix\Bizproc\Api\Request\WorkflowTemplateService;

use Bitrix\Bizproc\Starter\Enum\ManualStartSurface;

final class PrepareStartParametersRequest
{
	/**
	 * @param ManualStartSurface|null $manualStartSurface the surface the start form is being built for;
	 *     null when the caller is not a manual start of an employee, and then the parameters of the live
	 *     template row are prepared as before
	 */
	public function __construct(
		public readonly int $templateId,
		public readonly array $complexDocumentType,
		public readonly array $requestParameters,
		public readonly int $targetUserId,
		public readonly int $eventType = \CBPDocumentEventType::Manual, // Create, Edit
		public readonly ?ManualStartSurface $manualStartSurface = null,
	)
	{}
}

<?php

namespace Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService;

use Bitrix\Bizproc\Api\Enum\Template\TemplatePublicationType;

final class RecordPublicationRequest
{
	/**
	 * @param array $templateFields fields the caller has just written into the template, so that the version
	 * does not have to read them back; what is missing here is read from the template row
	 */
	public function __construct(
		public readonly int $templateId,
		public readonly int $userId,
		public readonly TemplatePublicationType $publicationType,
		public readonly array $templateFields = [],
	)
	{}
}

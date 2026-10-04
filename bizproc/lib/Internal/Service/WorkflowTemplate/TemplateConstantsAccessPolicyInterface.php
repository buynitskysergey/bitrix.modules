<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\WorkflowTemplate;

interface TemplateConstantsAccessPolicyInterface
{
	public function canWrite(int $templateId, array $documentType, int $userId): bool;
}

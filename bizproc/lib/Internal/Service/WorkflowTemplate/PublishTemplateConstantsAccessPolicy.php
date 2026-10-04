<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\WorkflowTemplate;

final class PublishTemplateConstantsAccessPolicy implements TemplateConstantsAccessPolicyInterface
{
	public function __construct(
		private readonly TemplatePersistAccessService $templatePersistAccessService,
	) {}

	public function canWrite(int $templateId, array $documentType, int $userId): bool
	{
		return $this->templatePersistAccessService->canPersist(
			$templateId,
			$documentType,
			$userId,
			publish: true,
		);
	}
}

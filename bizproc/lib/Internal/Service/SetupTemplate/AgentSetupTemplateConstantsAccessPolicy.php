<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\SetupTemplate;

use Bitrix\Bizproc\Internal\Service\WorkflowTemplate\TemplateConstantsAccessPolicyInterface;
use Bitrix\Bizproc\Internal\Service\WorkflowTemplate\TemplatePersistAccessService;
use Bitrix\Bizproc\Public\Provider\WorkflowTemplate\AiAgentProvider;

final class AgentSetupTemplateConstantsAccessPolicy implements TemplateConstantsAccessPolicyInterface
{
	public function __construct(
		private readonly AiAgentProvider $aiAgentProvider,
		private readonly TemplatePersistAccessService $templatePersistAccessService,
	) {}

	public function canWrite(int $templateId, array $documentType, int $userId): bool
	{
		if (!$this->templatePersistAccessService->isExistingTemplateOfDocumentType($templateId, $documentType))
		{
			return false;
		}

		$user = new \CBPWorkflowTemplateUser($userId);

		return $this->aiAgentProvider->canManageLaunchedTemplate(
			$templateId,
			$userId,
			$user->isAdmin(),
		);
	}
}

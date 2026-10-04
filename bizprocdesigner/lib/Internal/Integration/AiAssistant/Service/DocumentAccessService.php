<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Public\Entity\Document\Workflow;
use Bitrix\Bizproc\Public\Provider\WorkflowTemplate\AiAgentProvider;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\Main\DI\ServiceLocator;

final readonly class DocumentAccessService
{
	private AiAgentProvider $aiAgentProvider;

	public function __construct(?AiAgentProvider $aiAgentProvider = null)
	{
		$this->aiAgentProvider = $aiAgentProvider
			?? ServiceLocator::getInstance()->get(AiAgentProvider::class);
	}

	public function canCreate(int $userId, DocumentDescription $documentType): bool
	{
		return \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$userId,
			$documentType->toBizprocComplexType(),
		);
	}

	/**
	 * Type-aware authoring guard for the AI-agent integration.
	 *
	 * Authoring on the WORKFLOW pseudo type is admin-only by design (fix #2: a non-admin could
	 * otherwise save a global collector template with a trigger and read other users' private data).
	 * That rule also denied the legitimate owner of their own running AI-agent copy, which this guard
	 * restores without weakening the boundary:
	 *  - an admin keeps full WORKFLOW authoring (the admin-only rule stays intact);
	 *  - a non-admin passes only as the owner of their own started AI-agent launched copy
	 *    (ACTIVATED_BY, requireStarted), which also closes the IDOR by a foreign templateId;
	 *  - any other non-admin (a global/foreign WORKFLOW template) stays denied.
	 *
	 * For real document types (CRM/tasks general designer) it stays the legacy CreateWorkflow check.
	 *
	 * @param int $templateId Launched copy id (only consulted for the WORKFLOW pseudo type).
	 * @param bool $isUserAdmin Whether the acting user is an admin (full bypass on the pseudo type).
	 */
	public function canManageDocument(
		int $userId,
		DocumentDescription $documentType,
		int $templateId,
		bool $isUserAdmin,
	): bool
	{
		if ($this->isWorkflowPseudoType($documentType))
		{
			if ($isUserAdmin)
			{
				return true;
			}

			return $this->aiAgentProvider->canManageLaunchedTemplate(
				$templateId,
				$userId,
				isUserAdmin: false,
				requireStarted: true,
			);
		}

		return $this->canCreate($userId, $documentType);
	}

	private function isWorkflowPseudoType(DocumentDescription $documentType): bool
	{
		return $documentType->toBizprocComplexType() === Workflow::getComplexType();
	}
}

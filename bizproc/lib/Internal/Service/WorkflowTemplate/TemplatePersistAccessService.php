<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\WorkflowTemplate;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Api\Service\WorkflowAccessService;
use Bitrix\Bizproc\Api\Service\WorkflowTemplateHistoryService;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;

final class TemplatePersistAccessService
{
	private array $templateAccessInfo = [];

	public function __construct(
		private readonly WorkflowAccessService $workflowAccessService = new WorkflowAccessService(),
		private readonly TemplateAccessService $templateAccessService = new TemplateAccessService(),
		private readonly WorkflowTemplateHistoryService $historyService = new WorkflowTemplateHistoryService(),
	) {}

	public function canPersist(int $templateId, array $documentType, int $userId, bool $publish): bool
	{
		$template = $this->getTemplateAccessInfo($templateId);
		if ($template !== null)
		{
			if (!\CBPHelper::isEqualDocument($template['documentType'], $documentType))
			{
				return false;
			}

			if ($template['isNodes'])
			{
				return $publish
					? $this->templateAccessService->canPublish($templateId, $userId, $documentType)
					: $this->templateAccessService->canEdit($templateId, $userId, $documentType);
			}
		}

		return $this->workflowAccessService->canCreateWorkflow($documentType, $userId);
	}

	public function isExistingTemplateOfDocumentType(int $templateId, array $documentType): bool
	{
		$template = $this->getTemplateAccessInfo($templateId);

		return $template !== null && \CBPHelper::isEqualDocument($template['documentType'], $documentType);
	}

	/** @return array{documentType: string[], isNodes: bool, inHistory: bool}|null */
	public function getTemplateAccessInfo(int $templateId): ?array
	{
		if ($templateId <= 0)
		{
			return null;
		}

		if (array_key_exists($templateId, $this->templateAccessInfo))
		{
			return $this->templateAccessInfo[$templateId];
		}

		$row = WorkflowTemplateTable::query()
			->setSelect(['TYPE', 'SYSTEM_CODE', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'])
			->where('ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		$this->templateAccessInfo[$templateId] = $row ? [
			'documentType' => [(string)$row['MODULE_ID'], (string)$row['ENTITY'], (string)$row['DOCUMENT_TYPE']],
			'isNodes' => $row['TYPE'] === WorkflowTemplateType::Nodes->value,
			'inHistory' => $this->historyService->isTemplateRowInHistory($row),
		] : null;

		return $this->templateAccessInfo[$templateId];
	}

	public function resetTemplateAccessInfo(int $templateId): void
	{
		unset($this->templateAccessInfo[$templateId]);
	}
}

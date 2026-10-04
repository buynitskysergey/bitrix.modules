<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentTemplate;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\WorkflowTemplateIdentifier;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Psr\Container\NotFoundExceptionInterface;

final readonly class AgentWorkflowResolverService
{
	private AiAssistantWorkflowTemplateConverterService $converterService;

	public function __construct(
		?AiAssistantWorkflowTemplateConverterService $converterService = null,
	)
	{
		$this->converterService = $converterService
			?? Container::getAiAssistantWorkflowTemplateConverterService();
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function resolveByTemplateId(int $templateId, int $userId): ?WorkflowTemplateIdentifier
	{
		$template = $this->getTemplate($templateId, ['ID', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE', 'NAME', 'MODIFIED']);
		if ($template === null)
		{
			return null;
		}

		$draft = $this->getNewestDraft($templateId, $userId);

		return new WorkflowTemplateIdentifier(
			documentDescription: new DocumentDescription(
				module: $template->getModuleId(),
				entityType: $template->getEntity(),
				documentType: $template->getDocumentType(),
			),
			templateId: $template->getId(),
			draftId: $draft?->getId(),
			name: $template->getName(),
			modified: $template->getModified(),
		);
	}

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 * @throws ObjectPropertyException
	 * @throws CircularDependencyException
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function getAgentTemplate(WorkflowTemplateIdentifier $identifier): ?AgentTemplate
	{
		$templateData = $this->getDraftOrTemplateData($identifier);
		if (!$templateData)
		{
			return null;
		}

		// REST agent read-path: the external agent is the only reader that receives frame overlays.
		$result = $this->converterService->convertFromTemplateArrayToAgentTemplate($templateData, RequestSource::Rest);
		if (!$result->isSuccess())
		{
			Container::getDefaultLogger()->error(
				'Agent workflow resolver convert errors: ' . implode(',', $result->getErrorMessages()),
			);

			return null;
		}

		return $this->converterService->getAgentTemplate();
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getDraftOrTemplateData(WorkflowTemplateIdentifier $identifier): ?array
	{
		if ($identifier->draftId)
		{
			$draft = WorkflowTemplateDraftTable::query()
				->where('ID', $identifier->draftId)
				->setSelect(['*'])
				->setLimit(1)
				->fetchObject()
			;
			$draftTemplate = $draft?->getTemplateData()['TEMPLATE'] ?? null;
			if ($draftTemplate)
			{
				return $draftTemplate;
			}
		}

		return $this->getTemplate((int)$identifier->templateId, ['ID', 'TEMPLATE'])?->getTemplate();
	}

	/**
	 * The row of a node template, admitting non-system node templates only - that filter is what makes an
	 * identifier of this service mean "a template of the contour".
	 *
	 * Which columns to read is the caller's part: resolving an identifier needs the scalar header of the
	 * row, while the serialized graph - tens of kilobytes on a large template - is read only where the
	 * graph itself is answered with.
	 *
	 * @param string[] $select
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getTemplate(int $templateId, array $select): ?EO_WorkflowTemplate
	{
		if ($templateId <= 0)
		{
			return null;
		}

		return WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('SYSTEM_CODE')
			->setSelect($select)
			->setLimit(1)
			->fetchObject()
		;
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getNewestDraft(int $templateId, int $userId): ?EO_WorkflowTemplateDraft
	{
		// CREATED holds whole seconds, so two drafts written within one second are equally new and the
		// order between them would be up to the database. ID breaks the tie by the order of the writes.
		return WorkflowTemplateDraftTable::query()
			->where('TEMPLATE_ID', $templateId)
			->where('USER_ID', $userId)
			->setOrder(['CREATED' => 'DESC', 'ID' => 'DESC'])
			->setSelect(['ID'])
			->setLimit(1)
			->fetchObject()
		;
	}
}

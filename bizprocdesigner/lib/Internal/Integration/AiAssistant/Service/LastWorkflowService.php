<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentTemplate;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\WorkflowTemplateIdentifier;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Psr\Container\NotFoundExceptionInterface;

final readonly class LastWorkflowService
{
	private AiAssistantWorkflowTemplateConverterService $aiAssistantWorkflowTemplateConverterService;

	public function __construct(
		?AiAssistantWorkflowTemplateConverterService $aiAssistantWorkflowTemplateConverterService = null,
	)
	{
		$this->aiAssistantWorkflowTemplateConverterService = $aiAssistantWorkflowTemplateConverterService
			?? Container::getAiAssistantWorkflowTemplateConverterService();
	}

	public function getAgentTemplate(int $userId): ?AgentTemplate
	{
		$lastEntities = [
			$this->getLastUserWorkflowTemplateDraft($userId),
			$this->getLastUserWorkflowTemplate($userId),
		];

		$newestEntityDate = null;
		$newestAgentTemplate = null;
		foreach ($lastEntities as $entity)
		{
			$entityDate = $this->getEntityDate($entity);
			if ($entityDate === null)
			{
				continue;
			}

			$agentTemplate = $this->getAgentTemplateFromEntity($entity);
			if ($agentTemplate === null)
			{
				continue;
			}

			if ($newestEntityDate === null || $entityDate > $newestEntityDate)
			{
				$newestEntityDate = $entityDate;
				$newestAgentTemplate = $agentTemplate;
			}
		}

		return $newestAgentTemplate;
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getLastUserWorkflowTemplateDraft(int $userId): ?EO_WorkflowTemplateDraft
	{
		return WorkflowTemplateDraftTable::query()
			->where('USER_ID', $userId)
			->setOrder(['CREATED' => 'DESC'])
			->setSelect(['*'])
			->setLimit(1)
			->fetchObject()
		;
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getLastUserWorkflowTemplate(int $userId): ?EO_WorkflowTemplate
	{
		return WorkflowTemplateTable::query()
			->where('USER_ID', $userId)
			->setOrder(['MODIFIED' => 'DESC'])
			->setSelect(['*'])
			->setLimit(1)
			->fetchObject()
		;
	}

	private function getEntityDate(EO_WorkflowTemplate|EO_WorkflowTemplateDraft|null $entity): ?DateTime
	{
		if ($entity instanceof EO_WorkflowTemplate)
		{
			return $entity->getModified();
		}

		if ($entity instanceof EO_WorkflowTemplateDraft)
		{
			return $entity->getCreated();
		}

		return null;
	}

	private function getTemplateDataFromEntity(EO_WorkflowTemplate|EO_WorkflowTemplateDraft $entity): ?array
	{
		if ($entity instanceof EO_WorkflowTemplate)
		{
			return $entity->getTemplate();
		}

		if ($entity instanceof EO_WorkflowTemplateDraft)
		{
			return $entity->getTemplateData()['TEMPLATE'] ?? null;
		}

		return null;
	}

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	private function getAgentTemplateFromEntity(EO_WorkflowTemplate|EO_WorkflowTemplateDraft $entity): ?AgentTemplate
	{
		$converted = null;
		$templateData = $this->getTemplateDataFromEntity($entity);
		if ($templateData)
		{
			$result = $this
				->aiAssistantWorkflowTemplateConverterService
				->convertFromTemplateArrayToAgentTemplate($templateData)
			;

			if ($result->isSuccess())
			{
				$converted = $this->aiAssistantWorkflowTemplateConverterService->getAgentTemplate();
			}
			else
			{
				$errors = implode(', ', $result->getErrorMessages());
				Container::getDefaultLogger()->error("Workflow template convert errors: $errors");
			}
		}

		return $converted && $converted->blocks->count() > 0 ? $converted : null;
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getUserLastWorkflowTemplateIdentifier(int $userId): ?WorkflowTemplateIdentifier
	{
		$lastEntities = [
			$this->getLastUserWorkflowTemplateDraft($userId),
			$this->getLastUserWorkflowTemplate($userId),
		];

		$newestEntityDate = null;
		$newestEntity = null;
		foreach ($lastEntities as $entity)
		{
			$entityDate = $this->getEntityDate($entity);
			if ($entityDate === null)
			{
				continue;
			}

			$templateData = $this->getTemplateDataFromEntity($entity);
			if ($templateData === null)
			{
				continue;
			}

			if ($newestEntityDate === null || $entityDate > $newestEntityDate)
			{
				$newestEntityDate = $entityDate;
				$newestEntity = $entity;
			}
		}

		return $this->getIdentifierForEntity($newestEntity);
	}

	private function getIdentifierForEntity(
		EO_WorkflowTemplate|EO_WorkflowTemplateDraft|null $entity,
	): ?WorkflowTemplateIdentifier
	{
		if ($entity instanceof EO_WorkflowTemplate)
		{
			return new WorkflowTemplateIdentifier(
				documentDescription: $this->makeDocumentDescriptionByEntity($entity),
				templateId: $entity->getId(),
			);
		}

		if ($entity instanceof EO_WorkflowTemplateDraft)
		{
			return new WorkflowTemplateIdentifier(
				documentDescription: $this->makeDocumentDescriptionByEntity($entity),
				templateId: $entity->getTemplateId(),
				draftId: $entity->getId(),
			);
		}

		return null;
	}

	private function makeDocumentDescriptionByEntity(
		EO_WorkflowTemplate|EO_WorkflowTemplateDraft $entity,
	): DocumentDescription
	{
		return new DocumentDescription(
			module: $entity->getModuleId(),
			entityType: $entity->getEntity(),
			documentType: $entity->getDocumentType(),
		);
	}
}

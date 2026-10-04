<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Api\Request\WorkflowTemplateService\SaveTemplateDraftRequest;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateService\SaveTemplateDraftResponse;
use Bitrix\Bizproc\Api\Service\WorkflowTemplateService;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlockCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnectionCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\Draft;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\WorkflowTemplateIdentifier;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Psr\Container\NotFoundExceptionInterface;

final class AgentDraftService
{
	private const META_FIELDS = [
		'NAME',
		'DESCRIPTION',
		'PARAMETERS',
		'VARIABLES',
		'CONSTANTS',
		'TEMPLATE_SETTINGS',
		'AUTO_EXECUTE',
	];

	private readonly AiAssistantDraftConverterService $draftConverterService;
	private readonly AiAssistantDraftCreatorService $draftCreatorService;
	private readonly DocumentAccessService $documentAccessService;

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	public function __construct(
		?AiAssistantDraftConverterService $draftConverterService = null,
		?AiAssistantDraftCreatorService $draftCreatorService = null,
		?DocumentAccessService $documentAccessService = null,
	)
	{
		$this->draftConverterService = $draftConverterService
			?? Container::getAiAssistantDraftConverterService();
		$this->draftCreatorService = $draftCreatorService
			?? Container::getAiAssistantDraftCreatorService();
		$this->documentAccessService = $documentAccessService ?? new DocumentAccessService();
	}

	/**
	 * Persists validated agent graph as template draft and pushes it to the open editor via pull.
	 */
	public function saveAndPush(
		WorkflowTemplateIdentifier $identifier,
		int $userId,
		AgentBlockCollection $blocks,
		AgentConnectionCollection $connections,
		RequestSource $source = RequestSource::Rest,
	): Result
	{
		$isUserAdmin = (new \CBPWorkflowTemplateUser($userId))->isAdmin();
		if (!$this->documentAccessService->canManageDocument(
			$userId,
			$identifier->documentDescription,
			(int)($identifier->templateId ?? 0),
			$isUserAdmin,
		))
		{
			return (new Result())->addError(
				new Error(Loc::getMessage('BIZPROCDESIGNER_AGENT_DRAFT_ERR_ACCESS_DENIED'), 'ACCESS_DENIED'),
			);
		}

		$draft = $this->draftConverterService->covertFromAgentInput(
			draftId: $identifier->draftId ?? 0,
			templateId: $identifier->templateId ?? 0,
			userId: $userId,
			blocks: $blocks,
			connections: $connections,
			documentType: $identifier->documentDescription->toBizprocComplexType(),
			source: $source,
		);

		$persistResult = $this->persist($identifier, $userId, $draft);
		if (!$persistResult->isSuccess())
		{
			return $persistResult;
		}

		$persistedDraftId = $persistResult->getTemplateDraftId();

		if ($persistedDraftId !== $draft->draftId)
		{
			$draft = new Draft(
				$persistedDraftId,
				$draft->templateId,
				$draft->userId,
				$draft->blocks,
				$draft->connections,
			);
		}

		$this->draftCreatorService->pushDraft($draft);

		return $persistResult;
	}

	private function persist(
		WorkflowTemplateIdentifier $identifier,
		int $userId,
		Draft $draft,
	): SaveTemplateDraftResponse
	{
		$connections = array_map(
			static fn(array $c): array => $c + ['createdAt' => null],
			$draft->connections->toArray(),
		);

		$converter = new NodesToTemplate($draft->blocks->toArray(), $connections);

		$fields = $this->prepareFields($identifier);
		$fields['DOCUMENT_TYPE'] = $identifier->documentDescription->toBizprocComplexType();
		$fields['TEMPLATE'] = $converter->convert();

		$request = new SaveTemplateDraftRequest(
			(int)($identifier->templateId ?? 0),
			[],
			$fields,
			new \CBPWorkflowTemplateUser($userId),
			false,
			(int)($identifier->draftId ?? 0),
		);

		return (new WorkflowTemplateService())->saveTemplateDraft($request);
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function prepareFields(WorkflowTemplateIdentifier $identifier): array
	{
		$fields = [
			'NAME' => '',
			'DESCRIPTION' => '',
			'PARAMETERS' => [],
			'VARIABLES' => [],
			'CONSTANTS' => [],
			'TEMPLATE_SETTINGS' => [],
			'AUTO_EXECUTE' => \CBPDocumentEventType::None,
		];

		$published = $this->getPublishedTemplateFields((int)($identifier->templateId ?? 0));
		$draftData = $this->getDraftTemplateData((int)($identifier->draftId ?? 0));

		foreach (self::META_FIELDS as $field)
		{
			if (isset($draftData[$field]))
			{
				$fields[$field] = $draftData[$field];
			}
			elseif (isset($published[$field]))
			{
				$fields[$field] = $published[$field];
			}
		}

		return $fields;
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	private function getPublishedTemplateFields(int $templateId): array
	{
		if ($templateId <= 0)
		{
			return [];
		}

		$row = WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->setSelect(['NAME', 'DESCRIPTION', 'PARAMETERS', 'VARIABLES', 'CONSTANTS', 'AUTO_EXECUTE'])
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? $row : [];
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getDraftTemplateData(int $draftId): array
	{
		if ($draftId <= 0)
		{
			return [];
		}

		$draft = WorkflowTemplateDraftTable::query()
			->where('ID', $draftId)
			->setSelect(['TEMPLATE_DATA'])
			->setLimit(1)
			->fetchObject()
		;

		$templateData = $draft?->getTemplateData();

		return is_array($templateData) ? $templateData : [];
	}
}

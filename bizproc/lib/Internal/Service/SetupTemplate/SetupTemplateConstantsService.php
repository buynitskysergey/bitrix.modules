<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\SetupTemplate;

use Bitrix\Bizproc\Api\Request\WorkflowTemplateService\SetConstantsRequest;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateService\SetConstantsResponse;
use Bitrix\Bizproc\Api\Service\WorkflowTemplateService;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\AiAgentRepository;
use Bitrix\Bizproc\Internal\Service\WorkflowTemplate\TemplatePersistAccessService;
use Bitrix\Bizproc\Public\Provider\WorkflowTemplate\AiAgentProvider;

final class SetupTemplateConstantsService
{
	private readonly WorkflowTemplateService $workflowTemplateService;

	public function __construct(?AgentSetupTemplateConstantsAccessPolicy $accessPolicy = null)
	{
		$this->workflowTemplateService = new WorkflowTemplateService(
			templateConstantsAccessPolicy: $accessPolicy ?? new AgentSetupTemplateConstantsAccessPolicy(
				new AiAgentProvider(new AiAgentRepository()),
				new TemplatePersistAccessService(),
			),
		);
	}

	public function setConstants(SetConstantsRequest $request): SetConstantsResponse
	{
		return $this->workflowTemplateService->setConstants($request);
	}
}

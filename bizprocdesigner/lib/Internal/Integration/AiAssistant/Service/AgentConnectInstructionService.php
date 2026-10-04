<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\WorkflowTemplateIdentifier;
use Bitrix\Main\Localization\Loc;

final class AgentConnectInstructionService
{
	/**
	 * Returns a self-contained markdown brief for an external coding agent.
	 * All REST methods are called on the same /rest/<id>/<secret>/ base the agent fetched this from.
	 */
	public function build(WorkflowTemplateIdentifier $identifier): string
	{
		$templateId = (int)$identifier->templateId;
		$module = $identifier->documentDescription->module;
		$entity = $identifier->documentDescription->entityType;

		$replace = [
			'#TEMPLATE_ID#' => $templateId,
			'#MODULE#' => $module,
			'#ENTITY#' => $entity,
		];

		$message = Loc::getMessage('BIZPROCDESIGNER_AGENT_CONNECT_INSTRUCTION', $replace)
			?? Loc::getMessage('BIZPROCDESIGNER_AGENT_CONNECT_INSTRUCTION', $replace, 'ru');

		return (string)$message;
	}
}

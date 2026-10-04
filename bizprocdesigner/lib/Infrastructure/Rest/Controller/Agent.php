<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Controller;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\ConnectInstructionDto;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Response\ConnectResponse;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentConnectInstructionService;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;

/**
 * REST V3 controller for agent connection.
 *
 * bizprocdesigner.agent.connect - returns a self-contained markdown brief for an external coding agent.
 *
 * No #[DtoType] here, unlike every other controller of the contour, and that is deliberate: the attribute
 * feeds the auto-wire of a Request, connectAction() takes none, and carrying it would publish
 * agent.field.get and agent.field.list on top of the eight actions the brief promises to answer.
 * AgentCest holds the surface to those eight.
 */
final class Agent extends AbstractAgentRestController
{
	/**
	 * Returns a markdown instruction brief that an external AI agent can use to understand and edit
	 * the workflow template its token is bound to.
	 *
	 * @restMethod bizprocdesigner.agent.connect
	 * @throws AccessDeniedException
	 * @throws EntityNotFoundException
	 */
	public function connectAction(): ConnectResponse
	{
		$identifier = $this->resolveAndAuthorizeBoundTemplate();

		$instruction = new ConnectInstructionDto();
		$instruction->templateId = (int)$identifier->templateId;
		$instruction->instruction = (new AgentConnectInstructionService())->build($identifier);

		return new ConnectResponse($instruction);
	}
}

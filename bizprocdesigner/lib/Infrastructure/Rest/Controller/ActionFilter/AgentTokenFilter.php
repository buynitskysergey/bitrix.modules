<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\ActionFilter;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentTokenGuard;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;

/**
 * Judges the authorizing agent token on every action of the contour, before the action is reached.
 *
 * A pre-filter rather than a step of an action, because an action is not always run: a repeat under an
 * idempotency key is answered from a stored response, and a key reused under a changed body ends the call
 * outright. Standing ahead of that filter is what makes the token judged on every path an action can take
 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\AbstractAgentRestController::getDefaultPreFilters()}).
 *
 * It holds the eight actions of the contour, not everything the module publishes: the stock field.get and
 * field.list the core adds for every controller carrying #[DtoType] are answered by
 * {@see \Bitrix\Rest\V3\Realisation\Controller\Field} and reach no pre-filter of the owning controller.
 *
 * Judged here is what a token answers for on its own: ours, active, not expired, still bound to a template.
 * Whether the binding is the template the call asks for stays in the action, where the requested identifier
 * is known. The refusal is the AccessDeniedException of every unauthorized call: moving a check earlier
 * must not make the state of a token readable off the status of the response.
 */
final class AgentTokenFilter extends Base
{
	/**
	 * @throws AccessDeniedException
	 */
	public function onBeforeAction(Event $event): ?EventResult
	{
		if (AgentTokenGuard::forCurrentRequest()->getBoundTemplateId($this->passwordId()) === null)
		{
			throw new AccessDeniedException();
		}

		return null;
	}

	/**
	 * The token the call authorized with, or null when there is none to read - a call authorized by
	 * anything other than a webhook, or a controller reached outside the REST transport. Either way the
	 * contour has no agent token to judge, which is a refusal: every action of it is bound to one.
	 */
	private function passwordId(): ?string
	{
		$controller = $this->getAction()?->getController();

		return $controller instanceof RestController
			? $controller->getServer()?->getPasswordId()
			: null;
	}
}

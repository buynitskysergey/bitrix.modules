<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\ExternalAccess\Controller\ActionFilter;

use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Infrastructure\Controller\ErrorCodeDictionary;
use Bitrix\Note\Infrastructure\ExternalAccess\Controller\BaseController;
use Bitrix\Note\Infrastructure\ExternalAccess\Controller\ExternalAccessContext;
use Bitrix\Note\Internal\Service\ExternalAccess\PublicLinkResolver;
use Bitrix\Note\Internal\Service\ExternalAccess\VisitorSessionService;

/**
 * The single place where a public link is checked before an action is entered.
 *
 * The token is read here, not by the action: public actions have no "knowledge base" and no
 * "level" parameter at all, so nothing an action receives can widen its scope.
 *
 * The chain of questions about the token itself belongs to PublicLinkResolver, shared with the
 * public page component, which decides the fate of a request before it mounts anything. The filter
 * adds the question the resolver deliberately does not ask - the password confirmation -
 * and hands the resolved link to the controller. One code covers every refusal - no token,
 * disabled, expired, reissued, archived base, closed tariff - so neither the code nor the text
 * tells "there was none" from "it was closed".
 */
class ExternalAccessLink extends Base
{
	/**
	 * Actions allowed in before the password confirmation is checked, by explicit full name: an
	 * error in this list equals a leak, so nothing here is decided by a naming convention or by a
	 * flag on the action.
	 *
	 * The name is the one the engine really resolves - with the class name whole, `Controller`
	 * suffix included. A mismatch with the rebuilt name below errs closed and not open: the action
	 * simply falls out of the list and the filter demands the password for it, so the confirmation
	 * of a password would stop working rather than let anyone past the check.
	 */
	private const PASSWORD_ACTIONS = [
		'note.external.PasswordController.confirm',
	];

	private const NAMESPACE_CODE = 'external';

	public function __construct(
		private readonly PublicLinkResolver $linkResolver = new PublicLinkResolver(),
		private readonly VisitorSessionService $visitorSessionService = new VisitorSessionService(),
	)
	{
		parent::__construct();
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		$action = $event->getParameter('action');
		if (!$action instanceof Action)
		{
			return $this->denied();
		}

		$controller = $action->getController();
		if (!$controller instanceof BaseController)
		{
			return $this->denied();
		}

		$link = $this->linkResolver->resolve((string)$controller->getRequest()->get('token'));
		if ($link === null)
		{
			return $this->denied();
		}

		// The question about the password has a single form: for a link without one the answer is
		// true, so the knowledge of whether a password exists stays inside the visitor session
		// service. This filter only asks it.
		if (
			!$this->visitorSessionService->isConfirmed($link->linkRecordId)
			&& !in_array($this->resolveFullActionName($action), self::PASSWORD_ACTIONS, true)
		)
		{
			return $this->passwordRequired();
		}

		$controller->setExternalAccessContext(new ExternalAccessContext(
			$link->linkRecordId,
			$link->collectionId,
			$link->accessLevel,
		));

		return null;
	}

	/**
	 * The engine keeps only the short action name, so the full name of the allow-list is rebuilt
	 * from the controller class and the namespace code of the public circuit.
	 *
	 * The class name goes in whole, `Controller` suffix included, because that is the name
	 * `Main\Engine\Resolver::buildControllerClassName()` resolves: after the namespace alias
	 * matches, the last segment of the action name is taken as the class name as it is, neither
	 * suffixed nor recased. The short form the contract used to promise resolved to a class that
	 * does not exist, and the whole module calls its actions the same way
	 * (`note.infrastructure.CollectionController.list`).
	 */
	private function resolveFullActionName(Action $action): string
	{
		$shortClassName = (new \ReflectionClass($action->getController()))->getShortName();

		return 'note.' . self::NAMESPACE_CODE . '.' . $shortClassName . '.' . $action->getName();
	}

	private function denied(): EventResult
	{
		return $this->refuse(ErrorCodeDictionary::EXTERNAL_ACCESS_DENIED);
	}

	private function passwordRequired(): EventResult
	{
		return $this->refuse(ErrorCodeDictionary::EXTERNAL_ACCESS_PASSWORD_REQUIRED);
	}

	private function refuse(string $code): EventResult
	{
		$this->addError(new Error(Loc::getMessage($code), $code));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}
}

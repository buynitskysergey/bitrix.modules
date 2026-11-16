<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\ExternalAccess\Controller;

use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\ActionFilter\Scope;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\SystemException;
use Bitrix\Note\Infrastructure\ExternalAccess\Controller\ActionFilter\ExternalAccessLink;
use Bitrix\Note\Internal\Service\ExternalAccess\ScopedDocumentReader;
use Bitrix\Note\Internal\Service\ExternalAccess\ScopedListReader;
use Bitrix\Note\Internal\Service\ExternalAccess\ScopedTreeReader;

/**
 * The base of the public circuit: requests of a visitor who is not a portal employee.
 *
 * The set of pre-filters is overridden whole, without calling the parent, because the point of the
 * separate namespace is exactly that there is no Authentication in it. Nothing is relaxed on the
 * controllers of the ordinary circuit for this - they keep their own set untouched.
 *
 * Csrf stays on: an anonymous visitor does have a working token, bitrix_sessid() is derived from
 * the core session and does not depend on the user.
 *
 * An action never receives the link as a parameter: the context is set by the filter and the
 * action reaches the knowledge base only through a ready scoped reader, so a later action cannot
 * read someone else's document even if its author never thought about isolation.
 */
abstract class BaseController extends Controller
{
	private ?ExternalAccessContext $externalAccessContext = null;

	protected function getDefaultPreFilters(): array
	{
		return [
			new HttpMethod([HttpMethod::METHOD_POST]),
			new Csrf(),
			new Scope(Scope::AJAX),
			new ExternalAccessLink(),
		];
	}

	/**
	 * Called by the pre-filter only, through $event->getParameter('action')->getController().
	 */
	final public function setExternalAccessContext(ExternalAccessContext $context): void
	{
		$this->externalAccessContext = $context;
	}

	/**
	 * @throws SystemException when an action runs without the filter having set the context
	 */
	final protected function getExternalAccessContext(): ExternalAccessContext
	{
		if ($this->externalAccessContext === null)
		{
			throw new SystemException('Note external access: the link context is not set');
		}

		return $this->externalAccessContext;
	}

	/**
	 * @throws SystemException
	 */
	final protected function getScopedDocumentReader(): ScopedDocumentReader
	{
		return new ScopedDocumentReader($this->getExternalAccessContext());
	}

	/**
	 * @throws SystemException
	 */
	final protected function getScopedTreeReader(): ScopedTreeReader
	{
		return new ScopedTreeReader($this->getExternalAccessContext());
	}

	/**
	 * @throws SystemException
	 */
	final protected function getScopedListReader(): ScopedListReader
	{
		return new ScopedListReader($this->getExternalAccessContext());
	}
}

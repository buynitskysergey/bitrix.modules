<?php

declare(strict_types=1);

namespace Bitrix\Call\Controller\ActionFilter;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Rejects a state-changing call that a browser made from another site.
 *
 * The Csrf filter of the core is declared for the ajax scope only, so a REST action open to a guest has no
 * token behind it: the guest session is recognised by its cookie alone. Restricting the method to POST
 * takes away the top-level GET navigation, but an ordinary cross-site form posts just as well wherever the
 * cookie is not SameSite-bound.
 *
 * Origin is what separates the two: a browser always sends it on a cross-site POST and a page cannot forge
 * or drop it, while native clients send no Origin at all - which is why an absent header passes. This is a
 * defence against a browser, not against a client holding a stolen cookie; nothing but taking the action
 * away from guests would help there.
 */
class SameOrigin extends Base
{
	public const ERROR_FOREIGN_ORIGIN = 'foreign_origin';

	public function onBeforeAction(Event $event): ?EventResult
	{
		$request = $this->action->getController()->getRequest();

		$origin = trim((string)$request->getHeader('Origin'));
		if ($origin === '')
		{
			return null;
		}

		if ($this->isOwnOrigin($origin, (string)$request->getServer()->getHttpHost()))
		{
			return null;
		}

		$this->addError(new Error('Request origin is not allowed', self::ERROR_FOREIGN_ORIGIN));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}

	/**
	 * Is this Origin our own host.
	 *
	 * The comparison is against the raw Host header rather than against HttpRequest::getHttpHost(), which
	 * drops the port: a browser writes the port the same way into both Host and Origin - present when it is
	 * not the default one, absent when it is - so a portal answering on a port would otherwise never match
	 * its own pages. Host is no less trustworthy here than the check itself: a cross-site page chooses the
	 * Origin it is given, never the Host.
	 *
	 * Both schemes of our host are accepted because the scheme is not what the check is about - a foreign
	 * site differs by host - while a portal behind a TLS-terminating proxy cannot always tell which of the
	 * two the browser used.
	 */
	private function isOwnOrigin(string $origin, string $httpHost): bool
	{
		if ($httpHost === '')
		{
			return false;
		}

		return strcasecmp($origin, 'https://' . $httpHost) === 0
			|| strcasecmp($origin, 'http://' . $httpHost) === 0;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Engine\Action;
use Bitrix\Rest\V3\Exception\AccessDeniedException;

/**
 * The base of a CRM v3 method a portal administrator alone may call.
 *
 * The right is checked on the server, before the action, and for every action of the controller: a REST
 * scope says what an application was allowed to ask for, not who is asking, so a scope of its own would
 * leave the method open to any user of an application holding it.
 *
 * The right is that of an administrator of the **portal**, not of the CRM. The administrator of the CRM is
 * a different notion - it is the right to write the configuration of the CRM, and it says nothing about
 * the portal - so the two must not be substituted for one another. The facade below answers for the group
 * of portal administrators and for the cloud check alike, and is the only place that decides it.
 *
 * A method whose access model is this one extends this class instead of repeating the check. A subclass
 * that has a check of its own overrides {@see self::processBeforeAction()} and calls the parent first, so
 * that the administrator gate keeps its place ahead of everything else.
 */
abstract class AbstractPortalAdminController extends AbstractController
{
	protected function processBeforeAction(Action $action): bool
	{
		if (!parent::processBeforeAction($action))
		{
			return false;
		}

		if (!Container::getInstance()->getUserPermissions($this->userId)->isAdmin())
		{
			throw new AccessDeniedException();
		}

		return true;
	}
}

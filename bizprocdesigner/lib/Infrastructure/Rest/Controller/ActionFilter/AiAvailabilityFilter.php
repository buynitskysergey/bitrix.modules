<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\ActionFilter;

use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAvailabilityService;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Exception\AccessDeniedException;

final class AiAvailabilityFilter extends Base
{
	/**
	 * @throws AccessDeniedException
	 * @throws ArgumentException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function onBeforeAction(Event $event): ?EventResult
	{
		$userId = (int)CurrentUser::get()->getId();

		if (!(new AiAvailabilityService())->isAvailableForUser($userId))
		{
			throw new AccessDeniedException();
		}

		if (!Feature::instance()->isExternalAiAgentAccessible())
		{
			throw new AccessDeniedException();
		}

		return null;
	}
}

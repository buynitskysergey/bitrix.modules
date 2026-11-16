<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Controller\ActionFilter;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Note\Internal\Access\AccessController;
use Bitrix\Note\Internal\Access\ActionDictionary;
use Bitrix\Note\Internal\Integration\AiAssistant\HistorySourceResolver;
use Bitrix\Note\Internal\Service\History\SourceContext;
use Bitrix\Note\Internal\Service\License\LicenseService;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use InvalidArgumentException;

class NoteRestAccess extends Base
{
	public function onBeforeAction(Event $event): EventResult
	{
		SourceContext::reset();

		try
		{
			$controller = ($this->action ?? null)?->getController();
			if ($controller instanceof RestController)
			{
				$server = $controller->getServer();
				if ($server !== null)
				{
					SourceContext::replace((new HistorySourceResolver())->resolve(
						$server->getAuthType(),
						$server->getAuthData(),
					));
				}
			}
		}
		catch (InvalidArgumentException $exception)
		{
			throw new RequestValidationException([
				new Error($exception->getMessage(), 'source.type'),
			]);
		}

		// Tariff/tool gate before ACL: same denial as an ACL failure.
		if ($this->createLicenseService()->isAccessBlocked())
		{
			throw new AccessDeniedException();
		}

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_NOTE_ACCESS))
		{
			throw new AccessDeniedException();
		}

		return new EventResult(EventResult::SUCCESS, null, null, $this);
	}

	protected function createLicenseService(): LicenseService
	{
		return new LicenseService();
	}
}

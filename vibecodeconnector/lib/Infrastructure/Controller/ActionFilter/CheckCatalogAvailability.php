<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller\ActionFilter;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;

/**
 * Denies a catalog action to the user the catalog is not available to. Independent of the
 * client stub: the client makes no such call, but a direct request must not be served either.
 *
 * Non-final on purpose: the current user seam is overridden by a descendant in tests.
 */
class CheckCatalogAvailability extends Base
{
	public const ERROR_CODE = 'CATALOG_NOT_AVAILABLE';

	/**
	 * The catalog controller passes the registered service in: the container stays the single
	 * place the availability service is assembled in, the same one the client extensions read.
	 * The default keeps the dependency visible in the signature and serves direct construction.
	 */
	public function __construct(
		private readonly AvailabilityService $availabilityService = new AvailabilityService(),
	) {
		parent::__construct();
	}

	public function onBeforeAction(Event $event)
	{
		if ($this->availabilityService->isAvailableForUser($this->getCurrentUserId()))
		{
			return null;
		}

		$this->addError(new Error('The catalog is not available to the current user', self::ERROR_CODE));

		return new EventResult(EventResult::ERROR, handler: $this);
	}

	protected function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}
}

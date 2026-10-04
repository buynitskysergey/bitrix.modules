<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Repository;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

/**
 * Read-only access to the portal state the CRM onboarding trigger condition relies on.
 *
 * Wraps the query for the oldest user of the portal, which would otherwise make the condition
 * untestable in isolation. The read is memoized per instance: the trigger asks the condition
 * twice within one hit, and the instance comes from the ServiceLocator, which keeps it for the
 * whole request. Nothing is cached between hits, so no invalidation is needed.
 *
 * @internal
 */
class PortalRepository
{
	private ?DateTime $firstUserRegisterDate = null;
	private bool $isFirstUserRegisterDateRead = false;

	/**
	 * Returns the registration date of the oldest portal user, which is when the portal started.
	 *
	 * @return DateTime|null null when the portal has no users with a registration date.
	 */
	public function getFirstUserRegisterDate(): ?DateTime
	{
		if (!$this->isFirstUserRegisterDateRead)
		{
			$this->firstUserRegisterDate = $this->readFirstUserRegisterDate();
			$this->isFirstUserRegisterDateRead = true;
		}

		return $this->firstUserRegisterDate;
	}

	private function readFirstUserRegisterDate(): ?DateTime
	{
		$user = UserTable::query()
			->setSelect(['DATE_REGISTER'])
			->setOrder(['DATE_REGISTER'])
			->setLimit(1)
			->fetch()
		;

		if (!is_array($user) || !($user['DATE_REGISTER'] instanceof DateTime))
		{
			return null;
		}

		return $user['DATE_REGISTER'];
	}
}

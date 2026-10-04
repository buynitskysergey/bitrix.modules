<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\UserTable;

final class AiAvailabilityService
{
	private const USER_QUERY_CACHE_TTL = 3600;

	/**
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function isAvailableForUser(int $userId): bool
	{
		if (!Loader::includeModule('aiassistant'))
		{
			return false;
		}

		if (!Loader::includeModule('bizproc'))
		{
			return false;
		}

		return $this->userIsReal($userId);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	private function userIsReal(int $userId): bool
	{
		$user = UserTable::query()
			->where('ID', $userId)
			->where('IS_REAL_USER', true)
			->setSelect(['ID'])
			->setLimit(1)
			->setCacheTtl(self::USER_QUERY_CACHE_TTL)
			->fetch()
		;

		return !empty($user);
	}
}

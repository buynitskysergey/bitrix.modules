<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Humanresources;

use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\SystemException;

final class UserDepartmentAssigner
{
	/**
	 * @throws LoaderException
	 * @throws SystemException
	 */
	public function __construct()
	{
		if (!Loader::includeModule('humanresources'))
		{
			throw new SystemException('Module "humanresources" is not installed');
		}
	}

	public function reassignToDepartments(int $userId, array $departmentIds): void
	{
		if ($userId <= 0 || empty($departmentIds))
		{
			return;
		}

		Container::getUserDepartmentService()->assignToDepartments(
			userId: $userId,
			departmentIds: $departmentIds,
			replaceExisting: true,
		);
	}
}

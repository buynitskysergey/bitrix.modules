<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\User;

use Bitrix\Crm\V2\Public\Entity\User\Employee;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Collection;
use Bitrix\Main\Web\Uri;

final class EmployeeRepository
{
	/**
	 * @param int[] $userIds
	 * @return array<int, Employee>
	 */
	public function getByIds(array $userIds): array
	{
		Collection::normalizeArrayValuesByInt($userIds, false);
		if ($userIds === [])
		{
			return [];
		}

		$rows = (new \Bitrix\Crm\Service\Broker\User())->getBunchByIds($userIds);

		$result = [];
		$isCheckIntranet = Loader::includeModule('intranet');
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			if (!$isCheckIntranet || \Bitrix\Intranet\Util::isIntranetUser($id))
			{
				$photoUrl = $row['PHOTO_URL'] ?? null;
				$result[$id] = new Employee(
					id: $id,
					firstName: $row['NAME'] ?? null,
					lastName: $row['LAST_NAME'] ?? null,
					formattedName: $row['FORMATTED_NAME'] ?? null,
					personalGender: ($row['PERSONAL_GENDER'] ?? null) ?: null,
					showUrl: $row['SHOW_URL'] ?? null,
					photoUrl: is_string($photoUrl) ? new Uri($photoUrl) : null,
				);
			}
		}

		return $result;
	}
}

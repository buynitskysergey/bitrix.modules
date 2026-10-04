<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Dictionary;

/**
 * @internal
 */
class CustomFieldEnumDictionary
{
	/**
	 * @return int[]
	 */
	public function getEnumIds(int $enumFieldId): array
	{
		$result = [];
		$dbResult = \CUserFieldEnum::GetList([], ['USER_FIELD_ID' => $enumFieldId]);
		while ($enum = $dbResult->Fetch())
		{
			$result[] = (int)$enum['ID'];
		}

		return $result;
	}
}

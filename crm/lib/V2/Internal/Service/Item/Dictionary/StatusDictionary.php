<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Dictionary;

/**
 * @internal
 */
class StatusDictionary
{
	/**
	 * @return string[]
	 */
	public function getAllIdByStatusTypeId(string $statusTypeId): array
	{
		return array_keys(\CCrmStatus::GetStatusList($statusTypeId));
	}
}

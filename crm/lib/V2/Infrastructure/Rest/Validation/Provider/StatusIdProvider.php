<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\V2\Internal\Service\Item\Dictionary\StatusDictionary;

/**
 * @internal
 */
class StatusIdProvider implements ValidValuesProviderInterface
{
	/**
	 * @return string[]
	 */
	public static function getValidValues(Context $context): array
	{
		static $statusesIdMap = null;

		$statusTypeId = $context->getExtraArgs()['statusTypeId'] ?? null;
		if ($statusTypeId === null)
		{
			return [];
		}
		if (!isset($statusesIdMap[$statusTypeId]))
		{
			$statusesIdMap[$statusTypeId] = static::getDictionary()->getAllIdByStatusTypeId($statusTypeId);
		}

		return $statusesIdMap[$statusTypeId];
	}

	protected static function getDictionary(): StatusDictionary
	{
		return new StatusDictionary();
	}
}

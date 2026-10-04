<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\V2\Internal\Integration\Sale\LocationDictionary;
use Bitrix\Main\NotSupportedException;

/**
 * @internal
 */
class LocationIdProvider implements ValidValuesProviderInterface
{
	/**
	 * @return int[]
	 */
	public static function getValidValues(Context $context): array
	{
		if ($context->isShowValues()) // Show all IDs from DB table can impact performance
		{
			throw new NotSupportedException();
		}

		return static::getDictionary()->getAllId((array)$context->getValue());
	}

	protected static function getDictionary(): LocationDictionary
	{
		return new LocationDictionary();
	}
}

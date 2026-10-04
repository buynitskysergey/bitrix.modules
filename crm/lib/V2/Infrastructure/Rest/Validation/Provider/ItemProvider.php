<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\V2\Internal\Service\Item\Dictionary\ItemDictionary;
use Bitrix\Main\NotSupportedException;

/**
 * @internal
 */
class ItemProvider implements ValidValuesProviderInterface
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

		$userId = $context->getUserId();

		return static::getDictionary()->getAllIdByEntityType(
			$userId,
			$context->getEntityType(),
			(array)$context->getValue(),
		);
	}

	protected static function getDictionary(): ItemDictionary
	{
		return new ItemDictionary();
	}
}

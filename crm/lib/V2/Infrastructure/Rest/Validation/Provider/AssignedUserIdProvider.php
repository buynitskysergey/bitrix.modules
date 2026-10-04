<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\V2\Internal\Service\Item\Dictionary\UserDictionary;
use Bitrix\Main\NotSupportedException;

/**
 * @internal
 */
class AssignedUserIdProvider implements ValidValuesProviderInterface
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

		return static::getDictionary()->getAvailableAssignedUserIds(
			$context->getEntityType(),
			$context->getUserId(),
			(array)$context->getValue(),
		);
	}

	protected static function getDictionary(): UserDictionary
	{
		return new UserDictionary();
	}
}

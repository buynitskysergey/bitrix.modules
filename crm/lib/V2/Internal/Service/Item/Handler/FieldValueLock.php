<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Handler;

use Bitrix\Crm\V2\Public\ItemId;

final class FieldValueLock
{
	public static function getKey(ItemId $itemId, string $fieldName): string
	{
		if (in_array($fieldName, ['phone', 'email', 'web', 'im'], true))
		{
			$fieldName = 'FM';
		}

		return 'crm_field_value_' . hash('sha256', implode(':', [
			$itemId->getEntityType()->getId(),
			$itemId->getId(),
			$fieldName,
		]));
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\V2\Internal\Service\Item\Dictionary\CustomFieldEnumDictionary;

/**
 * @internal
 */
class CustomFieldEnumIdProvider implements ValidValuesProviderInterface
{
	/**
	 * @return int[]
	 */
	public static function getValidValues(Context $context): array
	{
		static $enumsIdMap = null;

		$customFieldId = $context->getExtraArgs()['customFieldId'] ?? null;
		if ($customFieldId === null)
		{
			return [];
		}
		if (!isset($enumsIdMap[$customFieldId]))
		{
			$enumsIdMap[$customFieldId] = static::getDictionary()->getEnumIds((int)$customFieldId);
		}

		return $enumsIdMap[$customFieldId];
	}

	protected static function getDictionary(): CustomFieldEnumDictionary
	{
		return new CustomFieldEnumDictionary();
	}
}

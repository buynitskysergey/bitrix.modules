<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\V2\Internal\Service\Item\Dictionary\CurrencyDictionary;

/**
 * @internal
 */
class CurrencyIdProvider implements ValidValuesProviderInterface
{
	/**
	 * @return string[]
	 */
	public static function getValidValues(Context $context): array
	{
		static $currenciesId = null;

		if ($currenciesId === null)
		{
			$currenciesId = static::getDictionary()->getAllId();
		}

		return $currenciesId;
	}

	protected static function getDictionary(): CurrencyDictionary
	{
		return new CurrencyDictionary();
	}
}

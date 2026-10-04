<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Dictionary;

/**
 * @internal
 */
class CurrencyDictionary
{
	/**
	 * @return string[]
	 */
	public function getAllId(): array
	{
		$currenciesId = array_keys(\CCrmCurrencyHelper::PrepareListItems());
		if ($currenciesId === [])
		{
			$currenciesId = [\CCrmCurrency::GetBaseCurrencyID()];
		}

		return $currenciesId;
	}
}

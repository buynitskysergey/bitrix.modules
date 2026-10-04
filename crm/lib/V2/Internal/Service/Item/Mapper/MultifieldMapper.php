<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Mapper;

use Bitrix\Crm\Multifield\Collection as LegacyMultifieldCollection;
use Bitrix\Crm\V2\Public\Entity\Item\EmailCollection;
use Bitrix\Crm\V2\Public\Entity\Item\EmailValue;
use Bitrix\Crm\V2\Public\Entity\Item\ImCollection;
use Bitrix\Crm\V2\Public\Entity\Item\ImValue;
use Bitrix\Crm\V2\Public\Entity\Item\PhoneCollection;
use Bitrix\Crm\V2\Public\Entity\Item\PhoneValue;
use Bitrix\Crm\V2\Public\Entity\Item\WebCollection;
use Bitrix\Crm\V2\Public\Entity\Item\WebValue;

/**
 * Converts legacy multifield collections (already filtered by type) to typed V2 collections.
 *
 * Each `to*` method always returns a non-null collection — empty if the source had no values.
 * That distinction matters for the Provider read-path: callers treat "null collection" as
 * "relation not loaded" and "empty collection" as "loaded, owner has no values".
 *
 * @internal
 */
final class MultifieldMapper
{
	public static function toPhoneCollection(LegacyMultifieldCollection $filtered): PhoneCollection
	{
		$collection = new PhoneCollection();
		foreach ($filtered as $legacyValue)
		{
			$countryCode = $legacyValue->getValueExtra()?->getCountryCode();

			$collection->add(new PhoneValue(
				valueType: $legacyValue->getValueType(),
				value: $legacyValue->getValue(),
				id: $legacyValue->getId(),
				countryCode: $countryCode,
			));
		}

		return $collection;
	}

	public static function toEmailCollection(LegacyMultifieldCollection $filtered): EmailCollection
	{
		$collection = new EmailCollection();
		foreach ($filtered as $legacyValue)
		{
			$collection->add(new EmailValue(
				valueType: $legacyValue->getValueType(),
				value: $legacyValue->getValue(),
				id: $legacyValue->getId(),
			));
		}

		return $collection;
	}

	public static function toWebCollection(LegacyMultifieldCollection $filtered): WebCollection
	{
		$collection = new WebCollection();
		foreach ($filtered as $legacyValue)
		{
			$collection->add(new WebValue(
				valueType: $legacyValue->getValueType(),
				value: $legacyValue->getValue(),
				id: $legacyValue->getId(),
			));
		}

		return $collection;
	}

	public static function toImCollection(LegacyMultifieldCollection $filtered): ImCollection
	{
		$collection = new ImCollection();
		foreach ($filtered as $legacyValue)
		{
			$collection->add(new ImValue(
				valueType: $legacyValue->getValueType(),
				value: $legacyValue->getValue(),
				id: $legacyValue->getId(),
			));
		}

		return $collection;
	}
}

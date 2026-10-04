<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Dictionary;

use Bitrix\Crm\EntityAddress;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\V2\Public\Entity\Item\AddressType;
use Bitrix\Main\Localization\Loc;

class AddressTypeRepository
{
	private AddressTypeTitleResolver $titleResolver;

	public function __construct()
	{
		$this->titleResolver = new AddressTypeTitleResolver();
	}

	public function getById(int $id, string $languageId): ?AddressType
	{
		foreach ($this->getList($languageId) as $addressType)
		{
			if ($addressType->getId() === $id)
			{
				return $addressType;
			}
		}

		return null;
	}

	/**
	 * @return list<AddressType>
	 */
	public function getList(string $languageId): array
	{
		$zoneId = EntityAddress::getZoneId();
		$portalLanguageId = Loc::getCurrentLang();
		$result = [];

		foreach (EntityAddressType::getAvailableTypesByZone($zoneId) as $typeId)
		{
			$typeId = (int)$typeId;
			$name = (string)EntityAddressType::resolveName($typeId);

			$result[] = new AddressType(
				$typeId,
				$name,
				$this->titleResolver->resolve($name, $languageId, $portalLanguageId),
			);
		}

		return $result;
	}
}

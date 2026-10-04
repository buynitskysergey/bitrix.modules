<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Lead;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Lead\AddressDto;
use Bitrix\Crm\V2\Public\Entity\Address\LeadAddress;

final class LeadAddressMapper
{
	public function mapToDto(LeadAddress $address): AddressDto
	{
		$dto = new AddressDto();
		foreach ($address->toArray() as $field => $value)
		{
			$dto->{$field} = $value;
		}

		return $dto;
	}

	public function mapToChanges(AddressDto $dto, array $fields): LeadAddress
	{
		$address = new LeadAddress();
		foreach (array_keys($fields) as $field)
		{
			match ($field) {
				'address1' => $address->setAddress1($dto->address1),
				'address2' => $address->setAddress2($dto->address2),
				'city' => $address->setCity($dto->city),
				'postalCode' => $address->setPostalCode($dto->postalCode),
				'region' => $address->setRegion($dto->region),
				'province' => $address->setProvince($dto->province),
				'country' => $address->setCountry($dto->country),
				'countryCode' => $address->setCountryCode($dto->countryCode),
			};
		}

		return $address;
	}
}

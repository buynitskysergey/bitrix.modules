<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Address;

use Bitrix\Crm\EntityAddress;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory\Lead as LeadFactory;
use Bitrix\Crm\V2\Public\Entity\Address\LeadAddress;
use Bitrix\Main\ObjectNotFoundException;

class LeadAddressService
{
	public function leadExists(int $leadId): bool
	{
		return $this->getLeadFactory()->getItem($leadId, ['ID']) !== null;
	}

	public function assertCanRead(int $leadId, int $userId): void
	{
		if (
			!$this->leadExists($leadId)
			|| !Container::getInstance()->getUserPermissions($userId)->item()->canRead(\CCrmOwnerType::Lead, $leadId)
		)
		{
			throw new ObjectNotFoundException("Lead not found: {$leadId}");
		}
	}

	public function assertCanUpdate(int $leadId, int $userId): void
	{
		if (
			!$this->leadExists($leadId)
			|| !Container::getInstance()->getUserPermissions($userId)->item()->canUpdate(\CCrmOwnerType::Lead, $leadId)
		)
		{
			throw new ObjectNotFoundException("Lead not found: {$leadId}");
		}
	}

	public function readPrimary(int $leadId): LeadAddress
	{
		$address = EntityAddress::getByOwner(EntityAddressType::Primary, \CCrmOwnerType::Lead, $leadId);

		return LeadAddress::fromArray([
			'address1' => $address['ADDRESS_1'] ?? '',
			'address2' => $address['ADDRESS_2'] ?? '',
			'city' => $address['CITY'] ?? '',
			'postalCode' => $address['POSTAL_CODE'] ?? '',
			'region' => $address['REGION'] ?? '',
			'province' => $address['PROVINCE'] ?? '',
			'country' => $address['COUNTRY'] ?? '',
			'countryCode' => $address['COUNTRY_CODE'] ?? '',
		]);
	}

	public function savePrimary(int $leadId, LeadAddress $address): void
	{
		$values = $address->toArray();
		EntityAddress::register(\CCrmOwnerType::Lead, $leadId, EntityAddressType::Primary, [
			'ADDRESS_1' => $values['address1'],
			'ADDRESS_2' => $values['address2'],
			'CITY' => $values['city'],
			'POSTAL_CODE' => $values['postalCode'],
			'REGION' => $values['region'],
			'PROVINCE' => $values['province'],
			'COUNTRY' => $values['country'],
			'COUNTRY_CODE' => $values['countryCode'],
		]);
	}

	public function updatePrimary(int $leadId, LeadAddress $changes): void
	{
		$current = $this->readPrimary($leadId);
		$merged = $current->applyChanges($changes);
		if ($current->toArray() !== $merged->toArray())
		{
			$this->savePrimary($leadId, $merged);
		}
	}

	public function hasPrimary(int $leadId): bool
	{
		return EntityAddress::getByOwner(EntityAddressType::Primary, \CCrmOwnerType::Lead, $leadId) !== null;
	}

	public function deletePrimary(int $leadId): void
	{
		if (!$this->hasPrimary($leadId))
		{
			throw new ObjectNotFoundException("Lead address not found: {$leadId}");
		}

		EntityAddress::unregister(\CCrmOwnerType::Lead, $leadId, EntityAddressType::Primary);
	}

	private function getLeadFactory(): LeadFactory
	{
		$factory = Container::getInstance()->getFactory(\CCrmOwnerType::Lead);
		if (!$factory instanceof LeadFactory)
		{
			throw new \RuntimeException('Lead factory is unavailable');
		}

		return $factory;
	}
}

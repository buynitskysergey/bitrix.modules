<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template\Macro;

use Bitrix\Crm\CompanyTable;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\Format\AddressFormatter;
use Bitrix\Crm\Format\RequisiteAddressFormatter;
use Bitrix\Crm\Requisite\EntityLink;
use Bitrix\Crm\RequisiteAddress;
use Bitrix\Main\Loader;

final class CrmCompanyMacroDataSource implements CompanyMacroDataSource
{
	public function __construct(private readonly \Closure $canReadCompany)
	{
	}

	public function load(int $userId, array $ids): ?array
	{
		if (!Loader::includeModule('crm'))
		{
			return null;
		}

		$companyId = (int)EntityLink::getDefaultMyCompanyId();
		if (
			$companyId <= 0
			|| !($this->canReadCompany)($userId, $companyId)
		)
		{
			return null;
		}

		$result = [];
		if (in_array('company.name', $ids, true))
		{
			$company = CompanyTable::query()
				->setSelect(['TITLE'])
				->where('ID', $companyId)
				->setLimit(1)
				->fetch()
			;
			if (!is_array($company))
			{
				return null;
			}
			$result['name'] = trim((string)($company['TITLE'] ?? ''));
		}

		$addressIds = array_intersect($ids, ['company.legalAddress', 'company.actualAddress']);
		if ($addressIds !== [])
		{
			$requisiteLink = EntityLink::getDefaultMyCompanyRequisiteLink();
			$requisiteId = (int)($requisiteLink['MC_REQUISITE_ID'] ?? 0);
			if (in_array('company.legalAddress', $addressIds, true))
			{
				$result['legalAddress'] = $this->getAddress($requisiteId, EntityAddressType::Registered);
			}
			if (in_array('company.actualAddress', $addressIds, true))
			{
				$result['actualAddress'] = $this->getAddress($requisiteId, EntityAddressType::Primary);
			}
		}

		return $result;
	}

	private function getAddress(int $requisiteId, int $addressType): string
	{
		if ($requisiteId <= 0)
		{
			return '';
		}

		$address = RequisiteAddress::getByOwner(
			$addressType,
			\CCrmOwnerType::Requisite,
			$requisiteId,
		);
		if (!is_array($address))
		{
			return '';
		}

		return AddressFormatter::getSingleInstance()->formatTextMultiline(
			$address,
			RequisiteAddressFormatter::getFormatByCountryId((int)($address['COUNTRY_ID'] ?? 0)),
		);
	}
}

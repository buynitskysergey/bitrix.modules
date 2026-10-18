<?php

namespace Bitrix\StaffTrack\Service\CheckIn;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class RegionAvailabilityService
{
	private const ALLOWED_REGIONS = [
		'ru', 'by', 'kz', 'uz', 'kg', 'am', 'az', 'ge', 'ua',
		'br', 'in', 'cn', 'tr', 'mx', 'co',
		'vn', 'id', 'th', 'ms', 'ja',
		'sc', 'tc', 'hi', 'ur',
		'ae', 'ar',
	];

	/**
	 * Zones for which geodata does NOT slow down automatic deletion (it is stored indefinitely).
	 * Differs from ALLOWED_REGIONS: Brazil (br) receives geo-features, but its data is deleted.
	 */
	private const RETENTION_ALLOWED_REGIONS = [
		'ru', 'by', 'kz', 'uz', 'kg', 'am', 'az', 'ge', 'ua',
		'in', 'cn', 'tr',
		'sc', 'tc', 'hi', 'ur',
	];

	private const GLOBAL_REGIONS = ['com', 'en', 'la'];

	private const ALLOWED_COUNTRIES = [
		'US', 'CA', 'AU', 'NZ',
		'RU', 'BY', 'KZ', 'UZ', 'KG', 'AM', 'AZ', 'GE', 'UA',
		'IN', 'CN', 'TR', 'VN', 'ID', 'TH', 'MY', 'JP', 'AE',
		// lat am
		'BR', 'MX', 'AR', 'CL', 'CO', 'PE', 'VE', 'EC', 'BO', 'PY',
		'UY', 'CR', 'PA', 'GT', 'HN', 'SV', 'NI', 'DO', 'CU', 'PR',
	];

	private const RESTRICTED_COUNTRIES = [
		'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
		'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
		'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
		'NO', 'IS', 'LI', 'GB', 'CH',
	];

	public function isGeoFeaturesAvailableByRegion(): bool
	{
		if (!$this->isCloud())
		{
			return true;
		}

		$region = $this->getPortalRegion();
		$country = $this->getRegistrationCountry();

		if (in_array($region, self::ALLOWED_REGIONS, true))
		{
			return true;
		}

		if (in_array($country, self::RESTRICTED_COUNTRIES, true))
		{
			return false;
		}

		if (in_array($region, self::GLOBAL_REGIONS, true))
		{
			return in_array($country, self::ALLOWED_COUNTRIES, true);
		}

		return false;
	}

	public function isGeoRetentionLimited(): bool
	{
		return !in_array($this->getLicenseRegion(), self::RETENTION_ALLOWED_REGIONS, true);
	}

	protected function getLicenseRegion(): string
	{
		return Application::getInstance()->getLicense()->getRegion() ?? 'en';
	}

	protected function isCloud(): bool
	{
		return Loader::includeModule('bitrix24');
	}

	protected function getPortalRegion(): string
	{
		return \CBitrix24::getPortalZone();
	}

	protected function getRegistrationCountry(): string
	{
		return mb_strtoupper(Option::get('bitrix24', 'REG_COUNTRY', ''));
	}
}

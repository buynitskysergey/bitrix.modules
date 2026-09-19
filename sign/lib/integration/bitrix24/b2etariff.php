<?php

namespace Bitrix\Sign\Integration\Bitrix24;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Error;

class B2eTariff
{
	private static ?self $instance = null;

	public static function instance(): self
	{
		if (!self::$instance)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function isB2eRestrictedInCurrentTariff(): bool
	{
		return Loader::includeModule('bitrix24')
			&& !\Bitrix\Bitrix24\Feature::isFeatureEnabled('sign_b2e')
		;
	}

	/**
	 * Lite goskey is a paid provider: the demo and the free cloud plans must not offer it.
	 * On self-hosted installations there is no bitrix24 module, so nothing is restricted.
	 */
	public function isGoskeyLiteRestrictedInCurrentTariff(): bool
	{
		return Loader::includeModule('bitrix24')
			&& (\CBitrix24::IsDemoLicense() || \CBitrix24::isFreeLicense())
		;
	}

	public function isB2eSignersCountRestricted(int $signersCount): bool
	{
		return $signersCount > $this->getB2eSignersCountLimit()
			&& $this->isB2eLimitedSignersForBitrix24()
		;
	}

	public function getB2eSignersCountLimitWithUnlimitCheck(): ?int
	{
		if ($this->isB2eLimitedSignersForBitrix24())
		{
			return $this->getB2eSignersCountLimit();
		}

		return null;
	}

	public function isB2eLimitedSignersForBitrix24(): bool
	{
		return Loader::includeModule('bitrix24')
			&& !\Bitrix\Bitrix24\Feature::isFeatureEnabled('sign_b2e_unlimited_signers')
		;
	}

	public function getB2eSignersCountLimit(): int
	{
		return 100;
	}

	public function getCommonAccessError(): Error
	{
		return new Error(
			Loc::getMessage('SIGN_INTEGRATION_BITRIX24_RESTRICTED_ON_TARIFF_ERROR'),
			'B2E_RESTRICTED_ON_TARIFF',
		);
	}

	public function getSignersCountAccessError(): Error
	{
		return new Error(
			Loc::getMessage('SIGN_INTEGRATION_BITRIX24_SIGNERS_LIMIT_REACHED_ON_TARIFF_ERROR', [
				'#SIGNERS_COUNT#' => $this->getB2eSignersCountLimit(),
			]),
			'B2E_SIGNERS_LIMIT_REACHED_ON_TARIFF',
		);
	}

	public function getGoskeyLiteAccessError(): Error
	{
		return new Error(
			Loc::getMessage('SIGN_INTEGRATION_BITRIX24_GOSKEY_LITE_RESTRICTED_ON_TARIFF_ERROR'),
			'GOSKEY_LITE_RESTRICTED_ON_TARIFF',
		);
	}
}

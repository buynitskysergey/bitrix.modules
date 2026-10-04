<?php

namespace Bitrix\Rest\Infrastructure\Market;

use Bitrix\Bitrix24\Public\Enum\VibePlus\MonetizationModel;
use Bitrix\Bitrix24\Public\Service\VibePlus\MonetizationModelProvider;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Bitrix\Rest\Internal\Integration\Bitrix24\MarketUrlProvider;
use Bitrix\Rest\Marketplace\Client;
use Bitrix\Rest\Service\RestOption;
use Bitrix\Rest\Service\ServiceContainer;

class MarketSubscription
{
	private const BASE_CACHE_DIR = 'rest/market_subscription';

	public function __construct(
		private readonly MarketOption $marketOption,
		private readonly ?MonetizationModelProvider $monetizationModelProvider = null,
	)
	{}

	public static function createByDefault(): self
	{
		return new self(new MarketOption(new RestOption()));
	}

	public function isRequiredSubscriptionModelStarted(): bool
	{
		if (!Client::isSubscriptionAccess())
		{
			return false;
		}

		if (!$this->isSubscriptionModel())
		{
			return false;
		}

		if ($this->marketOption->isNewPoliticsEnabled())
		{
			return true;
		}

		if (!$this->isTransitionPeriodEnabled() && !$this->isPaidAppsOrIntegrationsInstalled())
		{
			$this->marketOption->enableNewPolitics();

			return true;
		}

		return $this->isTransitionPeriodEnds();
	}

	public function isAvailableToPurchase(): bool
	{
		return Client::isSubscriptionAccess();
	}

	public function isActive(): bool
	{
		return Client::isSubscriptionAvailable();
	}

	public function isDemo(): bool
	{
		return Client::isSubscriptionDemo();
	}

	public function isDemoAvailable(): bool
	{
		return Client::isSubscriptionDemoAvailable();
	}

	public function getEndDate(): ?Date
	{
		return Client::getSubscriptionFinalDate();
	}

	public function getDiscount(): MarketDiscount
	{
		return new MarketDiscount(
			isAvailable: $this->marketOption->isDiscountAvailable(),
			percentage: $this->marketOption->getDiscountPercentage(),
			termsUrl: $this->marketOption->getDiscountTermsUrl(),
		);
	}

	public function isPaidAppsOrIntegrationsInstalled(): bool
	{
		$cache = Application::getInstance()->getCache();

		if (
			$cache->initCache(
				86400,
				'has_subscription_app_or_integration',
				self::BASE_CACHE_DIR,
			)
		)
		{
			return $cache->getVars();
		}

		$hasSubscriptionIntegrations =
			ServiceContainer::getInstance()->getIntegrationService()->hasPaidIntegrations()
			|| ServiceContainer::getInstance()->getAppService()->hasPaidApps()
		;
		$cache->startDataCache();
		$cache->endDataCache($hasSubscriptionIntegrations);

		return $hasSubscriptionIntegrations;
	}

	public function getBuyUrl(): string
	{
		return (new MarketUrlProvider)->getBuyUrl()->getUri();
	}

	public function getTransitionPeriodEndDate(): Date
	{
		return $this->marketOption->getSavedTransitionPeriodEndDate();
	}

	public function isTransitionPeriodEnds(): bool
	{
		return $this->isTransitionPeriodEnabled()
			&& $this->getTransitionPeriodEndDate()->getTimestamp() < time();
	}

	public function isTransitionPeriodEnabled(): bool
	{
		return $this->marketOption->isTransitionPeriodEnabled();
	}

	private function isSubscriptionModel(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		$monetizationModelProvider = $this->monetizationModelProvider ?? new MonetizationModelProvider();

		return $monetizationModelProvider->get() === MonetizationModel::SUBSCRIPTION;
	}
}

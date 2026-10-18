<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Tariff;

use Bitrix\Intranet\Util;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Feature\TariffMenuFeature;
use Bitrix\Mobile\Feature\TariffMenuPurchaseCtaFeature;
use Bitrix\Mobile\Internal\Integration\Bitrix24\License;
use Bitrix\Mobile\Internal\Integration\Market\Subscription;
use Bitrix\Mobile\Menu\Tariff\Dto\SubscriptionPlateData;
use Bitrix\Mobile\Menu\Tariff\Dto\TariffPlateData;
use Bitrix\MobileApp\Mobile;

final class TariffPlateProvider
{
	private License $license;
	private Subscription $subscription;
	private bool $isIos;

	public function __construct()
	{
		$this->license = new License();
		$this->subscription = new Subscription();
		$this->isIos = mb_strtolower(Mobile::getPlatform()) === 'ios';
	}

	/**
	 * Returns whether the tariff plate block should be visible for current user.
	 * Conditions: cloud portal + main feature flag enabled + intranet user + user has buy/manage right.
	 * Also serves as the server-side gate for actions the plate exposes (demo activation).
	 */
	public function isVisible(): bool
	{
		return
			Feature::isEnabled(TariffMenuFeature::class)
			&& Loader::includeModule('intranet')
			&& Util::isIntranetUser()
			&& $this->license->isCloud()
			&& $this->license->currentUserCanManageTariff()
		;
	}

	/**
	 * Returns the full tariff + subscription plate payload for the menu.
	 * When the plate must not be shown, returns ['visible' => false] — the single
	 * source of the wire `visible` flag the client reads.
	 */
	public function getData(): array
	{
		if (!$this->isVisible())
		{
			return ['visible' => false];
		}

		return [
			'visible' => true,
			'tariff' => $this->buildTariffData()->toArray(),
			'subscription' => $this->buildSubscriptionData()->toArray(),
		];
	}

	/**
	 * Returns only the subscription part (used after demo activation).
	 */
	public function getSubscriptionData(): ?array
	{
		if (!$this->license->isCloud())
		{
			return null;
		}

		return $this->buildSubscriptionData()->toArray();
	}

	private function buildTariffData(): TariffPlateData
	{
		$isDemo = $this->license->isDemo();
		$isFree = $this->license->isFree();
		$isActive = $this->license->isActive();
		$isAutopay = $this->license->isAutopay();
		$isDowngraded = $this->license->isDowngradedToFree();
		$daysLeft = $this->license->getDaysLeft();

		$state = (new TariffStateResolver())->resolve(
			isDemo: $isDemo,
			isFree: $isFree,
			isDowngraded: $isDowngraded,
			isActive: $isActive,
			isAutopay: $isAutopay,
			daysLeft: $daysLeft,
		);

		$cta = !$this->isIos && Feature::isEnabled(TariffMenuPurchaseCtaFeature::class);

		return TariffPlateData::make([
			'name' => $this->license->getName(),
			'type' => $this->license->getType(),
			'family' => $this->license->getFamily(),
			'state' => $state->value,
			'expireText' => $this->formatExpireDate($this->license->getExpireDate()),
			'daysLeft' => $daysLeft,
			'cta' => $cta,
			'isDemoAvailable' => $this->license->isDemoAvailable(),
		]);
	}

	private function buildSubscriptionData(): SubscriptionPlateData
	{
		$subscriptionData = $this->license->getSubscriptionData();
		$isPaid = (bool)($subscriptionData['isPaid'] ?? false);
		$isDemo = (bool)($subscriptionData['isDemo'] ?? false);
		$isDemoUsed = (bool)($subscriptionData['isDemoUsed'] ?? false);
		$isExpired = (bool)($subscriptionData['isExpired'] ?? false);
		$isAlmostExpired = (bool)($subscriptionData['isAlmostExpired'] ?? false);
		$endDate = $this->license->getSubscriptionEndDate();
		$isDemoAvailable = $this->subscription->isDemoAvailable();

		$state = (new SubscriptionStateResolver())->resolve(
			isPaid: $isPaid,
			isDemo: $isDemo,
			isDemoUsed: $isDemoUsed,
			isExpired: $isExpired,
			isAlmostExpired: $isAlmostExpired,
			isDemoAvailable: $isDemoAvailable,
		);

		$expireText = null;
		if ($endDate !== null && ($isPaid || $isDemo))
		{
			$expireText = $this->formatExpireDate($endDate);
		}

		$cta = !$this->isIos && Feature::isEnabled(TariffMenuPurchaseCtaFeature::class);

		return SubscriptionPlateData::make([
			'state' => $state->value,
			'expireText' => $expireText,
			'isDemoAvailable' => $isDemoAvailable,
			'cta' => $cta,
		]);
	}

	private function formatExpireDate(?DateTime $date): ?string
	{
		if ($date === null)
		{
			return null;
		}

		$format = Context::getCurrent()->getCulture()->getLongDateFormat();

		return FormatDate($format, $date->getTimestamp());
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Strategy;

use Bitrix\Bitrix24\Feature;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Portal;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\TrialExpireDate;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\ActivationStrategy;

final class PaidTariffRegionActivationStrategy implements ActivationStrategy
{
	private const TARIFF = 'pro';
	private const REGIONS = ['kz', 'uz'];
	private const TARIFF_FEATURE_IDS = ['rest_access', 'vibecode'];

	private bool $granted = false;

	private readonly Portal $portal;

	public function __construct(?Portal $portal = null)
	{
		$this->portal = $portal ?? new Portal();
	}

	public function isApplicable(): bool
	{
		return $this->portal->isCloudPortal() && in_array($this->resolveRegion(), self::REGIONS, true);
	}

	public function isActivated(): bool
	{
		return $this->findExpireDate() !== null || $this->hasTariffFeatureSet();
	}

	public function findExpireDate(): ?DateTime
	{
		return TrialExpireDate::forEdition(self::TARIFF);
	}

	public function activate(int $days): void
	{
		$expireDateBeforeActivation = $this->findExpireDate();

		try
		{
			$this->granted = $this->portal->grantTariff(self::TARIFF, $days);
		}
		catch (\Throwable $exception)
		{
			$this->markGranted($expireDateBeforeActivation);

			throw $exception;
		}
	}

	public function hasGrantedTrial(): bool
	{
		return $this->granted;
	}

	private function hasTariffFeatureSet(): bool
	{
		foreach (self::TARIFF_FEATURE_IDS as $featureId)
		{
			if (!Feature::isFeatureEnabled($featureId))
			{
				return false;
			}
		}

		return true;
	}

	private function resolveRegion(): string
	{
		return mb_strtolower(trim($this->portal->getRegion()));
	}

	private function markGranted(?DateTime $expireDateBeforeActivation): void
	{
		if ($expireDateBeforeActivation === null && $this->findExpireDate() !== null)
		{
			$this->granted = true;
		}
	}
}

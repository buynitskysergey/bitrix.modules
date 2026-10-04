<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Strategy;

use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Portal;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\TrialExpireDate;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\TrialTariffSet;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\VibePlusFeatureSet;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\ActivationStrategy;

final class VibePlusEditionTrialStrategy implements ActivationStrategy
{
	private bool $granted = false;
	private ?TrialTariffSet $tariffSet = null;

	private readonly Portal $portal;

	public function __construct(?Portal $portal = null)
	{
		$this->portal = $portal ?? new Portal();
	}

	public function isApplicable(): bool
	{
		return $this->portal->isCloudPortal() && $this->portal->isFreeLicense();
	}

	public function isActivated(): bool
	{
		return $this->findExpireDate() !== null && VibePlusFeatureSet::hasFullAccess();
	}

	public function findExpireDate(): ?DateTime
	{
		$tariff = $this->tariffSet()->findActiveTrialTariff();

		return $tariff === null ? null : TrialExpireDate::forEdition($tariff);
	}

	public function activate(int $days): void
	{
		$tariffSet = $this->tariffSet();
		$activeTariff = $tariffSet->findActiveTrialTariff();
		$targetTariff = $activeTariff ?? $tariffSet->getDefaultTariff();

		try
		{
			$grantedEdition = $this->portal->grantTariff($targetTariff, $days);
		}
		catch (\Throwable $exception)
		{
			$this->markGrantedEdition($activeTariff, $targetTariff);

			throw $exception;
		}

		if ($grantedEdition)
		{
			$this->granted = true;
		}

		$this->activateMissingFeatures($days);
	}

	public function hasGrantedTrial(): bool
	{
		return $this->granted;
	}

	private function activateMissingFeatures(int $days): void
	{
		$featureIds = VibePlusFeatureSet::getDisabledFeatureIds();
		if ($featureIds === [])
		{
			return;
		}

		try
		{
			$grantedFeatures = $this->portal->grantFeatures($featureIds, $days);
		}
		catch (\Throwable $exception)
		{
			$this->markGrantedFeatures($featureIds);

			throw $exception;
		}

		if ($grantedFeatures)
		{
			$this->granted = true;
		}
	}

	private function markGrantedEdition(?string $activeTariffBeforeActivation, string $targetTariff): void
	{
		if ($activeTariffBeforeActivation === null && TrialExpireDate::forEdition($targetTariff) !== null)
		{
			$this->granted = true;
		}
	}

	private function markGrantedFeatures(array $featureIdsBeforeActivation): void
	{
		if (VibePlusFeatureSet::getDisabledFeatureIds() !== $featureIdsBeforeActivation)
		{
			$this->granted = true;
		}
	}

	private function tariffSet(): TrialTariffSet
	{
		return $this->tariffSet ??= TrialTariffSet::forPortal($this->portal);
	}
}

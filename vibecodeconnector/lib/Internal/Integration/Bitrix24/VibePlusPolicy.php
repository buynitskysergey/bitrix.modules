<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\Feature;
use Bitrix\Bitrix24\Internal\Service\VibePlus\MonetizationModelResolver;
use Bitrix\Bitrix24\Public\Enum\VibePlus\MonetizationModel;
use Bitrix\Bitrix24\Public\Service\VibePlus\RuntimeStateProvider;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

class VibePlusPolicy
{
	private const VIBECODE_FEATURE = 'vibecode';
	private const REST_ACCESS_TRANSITION_PERIOD_FEATURE = 'rest_access_transition_period';

	private ?RuntimeStateProvider $runtimeStateProvider;
	private ?MonetizationModelResolver $monetizationModelResolver;
	private ?\Closure $featureEnabledProvider;
	private ?\Closure $paidTariffAccessProvider;

	public function __construct(
		?RuntimeStateProvider $runtimeStateProvider = null,
		?\Closure $featureEnabledProvider = null,
		?bool $isModuleIncluded = null,
		?MonetizationModelResolver $monetizationModelResolver = null,
		?\Closure $paidTariffAccessProvider = null,
	)
	{
		$isModuleIncluded ??= Loader::includeModule('bitrix24');
		$this->runtimeStateProvider = $isModuleIncluded
			? ($runtimeStateProvider ?? $this->resolveRuntimeStateProvider())
			: null;
		$this->monetizationModelResolver = $isModuleIncluded
			? ($monetizationModelResolver ?? new MonetizationModelResolver())
			: null;
		$this->featureEnabledProvider = $isModuleIncluded
			? ($featureEnabledProvider ?? static fn(string $feature): bool => Feature::isFeatureChargeable($feature)
				&& Feature::isFeatureEnabled($feature))
			: null;
		$this->paidTariffAccessProvider = $isModuleIncluded
			? ($paidTariffAccessProvider ?? static fn(): bool => \CBitrix24::isLicensePaid()
				|| \CBitrix24::IsNfrLicense()
				|| \CBitrix24::IsDemoLicense())
			: null;
	}

	public function isCatalogVisible(): bool
	{
		return
			$this->runtimeStateProvider === null
			|| $this->monetizationModelResolver === null
			|| $this->monetizationModelResolver->resolve() !== MonetizationModel::VIBE_PLUS
			|| $this->runtimeStateProvider->isVibePlusStartEnabled()
		;
	}

	public function getAvailability(): ?bool
	{
		if (
			$this->runtimeStateProvider === null
			|| $this->featureEnabledProvider === null
			|| $this->paidTariffAccessProvider === null
			|| $this->runtimeStateProvider->getMonetizationModel() !== MonetizationModel::VIBE_PLUS
			|| !$this->runtimeStateProvider->isLaunchDateReached()
		)
		{
			return null;
		}

		if (
			$this->runtimeStateProvider->hasVibePlusMonetizationModel()
			&& !$this->runtimeStateProvider->isVibePlusStartEnabled()
		)
		{
			return null;
		}

		if (
			($this->featureEnabledProvider)(self::VIBECODE_FEATURE)
			&& $this->runtimeStateProvider->isCurrentEditionActive()
		)
		{
			return true;
		}

		if (!$this->runtimeStateProvider->hasVibePlusMonetizationModel())
		{
			return false;
		}

		if (
			$this->runtimeStateProvider->isTransitionPeriodActive()
			&& ($this->featureEnabledProvider)(self::REST_ACCESS_TRANSITION_PERIOD_FEATURE)
			&& $this->runtimeStateProvider->isCurrentEditionActive()
		)
		{
			return true;
		}

		if (!$this->runtimeStateProvider->isTransitionPeriodInitialized())
		{
			return
				($this->paidTariffAccessProvider)()
				&& $this->runtimeStateProvider->isCurrentEditionActive();
		}

		return false;
	}

	private function resolveRuntimeStateProvider(): ?RuntimeStateProvider
	{
		$serviceLocator = ServiceLocator::getInstance();
		if (!$serviceLocator->has(RuntimeStateProvider::class))
		{
			return null;
		}

		return $serviceLocator->get(RuntimeStateProvider::class);
	}
}

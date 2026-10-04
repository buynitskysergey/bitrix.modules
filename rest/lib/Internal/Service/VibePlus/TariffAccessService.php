<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\VibePlus;

use Bitrix\Bitrix24\Feature;
use Bitrix\Bitrix24\Public\Enum\VibePlus\MonetizationModel;
use Bitrix\Bitrix24\Public\Service\VibePlus\RuntimeStateProvider;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Loader;
use Bitrix\Rest\Internal\Exception\VibePlus\FeatureNotAvailableOnCurrentPlanException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class TariffAccessService
{
	private const REST_ACCESS_FEATURE = 'rest_access';
	private const REST_ACCESS_TRANSITION_PERIOD_FEATURE = 'rest_access_transition_period';
	private const MARKET_APPLICATION_LIMIT_VARIABLE = 'market_application_limit';
	private const LOGGER_ID = 'rest.vibe_plus.tariff_access';

	private static bool $missingRuntimeStateProviderWarningLogged = false;
	private readonly ?\Closure $featureEnabledProvider;
	private readonly ?\Closure $variableProvider;
	private readonly ?\Closure $paidTariffRestAccessProvider;
	private readonly ?RuntimeStateProvider $runtimeStateProvider;
	private readonly LoggerInterface $logger;

	public function __construct(
		?\Closure $featureEnabledProvider = null,
		?\Closure $variableProvider = null,
		?bool $isBitrix24Included = null,
		?RuntimeStateProvider $runtimeStateProvider = null,
		?LoggerInterface $logger = null,
		?\Closure $paidTariffRestAccessProvider = null,
	)
	{
		$this->logger = $logger ?? (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
		$isBitrix24Included ??= Loader::includeModule('bitrix24');
		$this->featureEnabledProvider = $isBitrix24Included
			? ($featureEnabledProvider ?? static fn(string $feature): bool => Feature::isFeatureChargeable($feature)
				&& Feature::isFeatureEnabled($feature))
			: null;
		$this->variableProvider = $isBitrix24Included
			? ($variableProvider ?? static fn(string $variable): mixed => Feature::getVariable($variable))
			: null;
		$this->paidTariffRestAccessProvider = $isBitrix24Included
			? ($paidTariffRestAccessProvider ?? static fn(): bool => \CBitrix24::isLicensePaid()
				|| \CBitrix24::IsNfrLicense()
				|| \CBitrix24::IsDemoLicense())
			: null;
		$this->runtimeStateProvider = $isBitrix24Included
			? ($runtimeStateProvider ?? $this->resolveRuntimeStateProvider())
			: null;
	}

	public function getMarketApplicationLimit(): ?int
	{
		$result = $this->getMarketApplicationLimitResult();

		return $result->isApplicable() ? $result->getLimit() : null;
	}

	public function getMarketApplicationLimitResult(): MarketApplicationLimitResult
	{
		return $this->resolveMarketApplicationLimitResult(applyTransitionPeriodAccess: true);
	}

	public function getConfiguredMarketApplicationLimitResult(): MarketApplicationLimitResult
	{
		return $this->resolveMarketApplicationLimitResult(applyTransitionPeriodAccess: false);
	}

	private function resolveMarketApplicationLimitResult(
		bool $applyTransitionPeriodAccess,
	): MarketApplicationLimitResult
	{
		if (
			$this->featureEnabledProvider === null
			|| $this->variableProvider === null
			|| $this->runtimeStateProvider === null
		)
		{
			return MarketApplicationLimitResult::unavailable(
				'Vibe+ market application limit dependencies are unavailable.',
			);
		}

		try
		{
			if (
				$this->runtimeStateProvider->getMonetizationModel() !== MonetizationModel::VIBE_PLUS
				|| !$this->runtimeStateProvider->isLaunchDateReached()
			)
			{
				return MarketApplicationLimitResult::notApplicable();
			}

			if (
				$applyTransitionPeriodAccess
				&& !$this->runtimeStateProvider->isTransitionPeriodInitialized()
			)
			{
				return MarketApplicationLimitResult::applicable(-1);
			}

			if (
				$applyTransitionPeriodAccess
				&& $this->runtimeStateProvider->isTransitionPeriodActive()
				&& ($this->featureEnabledProvider)(self::REST_ACCESS_TRANSITION_PERIOD_FEATURE)
			)
			{
				return MarketApplicationLimitResult::applicable(-1);
			}

			$rawLimit = ($this->variableProvider)(self::MARKET_APPLICATION_LIMIT_VARIABLE);
		}
		catch (\Throwable $exception)
		{
			$this->logger->error(
				'Unable to resolve Vibe+ market application limit.',
				['exception' => $exception],
			);

			return MarketApplicationLimitResult::unavailable(
				'Unable to resolve Vibe+ market application limit.',
			);
		}

		$limit = $this->normalizeMarketApplicationLimit($rawLimit);
		if ($limit === null)
		{
			return MarketApplicationLimitResult::unavailable(
				'Vibe+ market application limit has an invalid value.',
			);
		}

		return MarketApplicationLimitResult::applicable($limit);
	}

	public function canInstallMarketApplication(
		?int $marketApplicationLimit,
		bool $isApplicationAvailable,
		bool $isCountAvailable,
		bool $isFreeApplicationAllowed,
	): bool
	{
		return $marketApplicationLimit !== null
			? $isCountAvailable
			: ($isApplicationAvailable && $isCountAvailable) || $isFreeApplicationAllowed;
	}

	public function shouldCountMarketApplication(
		?int $marketApplicationLimit,
		bool $isLocalApplication,
		bool $isFreeApplication,
	): bool
	{
		return $isFreeApplication || ($marketApplicationLimit !== null && !$isLocalApplication);
	}

	public function getRestAccessAvailability(): ?bool
	{
		if (
			$this->featureEnabledProvider === null
			|| $this->runtimeStateProvider === null
			|| $this->paidTariffRestAccessProvider === null
		)
		{
			return null;
		}

		if (!$this->runtimeStateProvider->hasVibePlusMonetizationModel())
		{
			return $this->runtimeStateProvider->getMonetizationModel() === MonetizationModel::PAID_TARIFF
				? ($this->paidTariffRestAccessProvider)()
				: null;
		}

		if (
			!$this->runtimeStateProvider->isVibePlusStarted()
			|| !$this->runtimeStateProvider->isLaunchDateReached()
			|| !$this->runtimeStateProvider->isTransitionPeriodInitialized()
			|| $this->runtimeStateProvider->isTransitionPeriodActive()
		)
		{
			return ($this->paidTariffRestAccessProvider)();
		}

		if (
			($this->featureEnabledProvider)(self::REST_ACCESS_FEATURE)
			&& $this->runtimeStateProvider->isCurrentEditionActive()
		)
		{
			return true;
		}

		return
			$this->runtimeStateProvider->isTransitionPeriodActive()
			&& ($this->featureEnabledProvider)(self::REST_ACCESS_TRANSITION_PERIOD_FEATURE);
	}

	public function isRestAccessAvailableByTariffFeatures(): bool
	{
		if ($this->featureEnabledProvider === null)
		{
			return false;
		}

		return
			(
				($this->featureEnabledProvider)(self::REST_ACCESS_FEATURE)
				&& (
					$this->runtimeStateProvider === null
					|| $this->runtimeStateProvider->isCurrentEditionActive()
				)
			)
			|| (
				$this->runtimeStateProvider !== null
				&& (
					!$this->runtimeStateProvider->isLaunchDateReached()
					|| $this->runtimeStateProvider->isTransitionPeriodActive()
				)
				&& ($this->featureEnabledProvider)(self::REST_ACCESS_TRANSITION_PERIOD_FEATURE)
			);
	}

	public function isUserWebhookDenied(): bool
	{
		return $this->getRestAccessAvailability() === false;
	}

	public function ensureRestAccessAvailable(): void
	{
		if ($this->getRestAccessAvailability() === false)
		{
			throw new FeatureNotAvailableOnCurrentPlanException();
		}
	}

	public function ensurePresetAvailable(): void
	{
		$this->ensureRestAccessAvailable();
	}

	public function getUserRestAvailability(
		bool $isPublishedApplication,
		?bool $restAccessAvailability = null,
	): ?bool
	{
		$availability = $restAccessAvailability ?? $this->getRestAccessAvailability();

		return $availability === false && $isPublishedApplication ? true : $availability;
	}

	public function getAPAuthAvailability(
		bool $isSystemPassword,
		?string $method,
		array $systemMethods,
		?bool $restAccessAvailability = null,
	): ?bool
	{
		$availability = $restAccessAvailability ?? $this->getRestAccessAvailability();
		if ($availability !== false)
		{
			return $availability;
		}

		return $isSystemPassword
			&& ($method === null || in_array($method, $systemMethods, true));
	}

	public function ensureAPAuthAvailable(
		bool $isSystemPassword,
		?string $method,
		array $systemMethods,
		?bool $restAccessAvailability = null,
	): void
	{
		if ($this->getAPAuthAvailability(
			$isSystemPassword,
			$method,
			$systemMethods,
			$restAccessAvailability,
		) === false)
		{
			throw new FeatureNotAvailableOnCurrentPlanException();
		}
	}

	public function isOutgoingEventHandlerAvailable(
		bool $isPublishedApplication,
		bool $isSystemHandler,
		?bool $isUserWebhookDenied = null,
	): bool
	{
		return $isPublishedApplication
			|| $isSystemHandler
			|| !($isUserWebhookDenied ?? $this->isUserWebhookDenied());
	}

	private function resolveRuntimeStateProvider(): ?RuntimeStateProvider
	{
		$serviceLocator = ServiceLocator::getInstance();
		if (!$serviceLocator->has(RuntimeStateProvider::class))
		{
			if (!self::$missingRuntimeStateProviderWarningLogged)
			{
				$this->logger->warning(
					'Vibe+ runtime state provider is unavailable; tariff restrictions are not applied.',
				);
				self::$missingRuntimeStateProviderWarningLogged = true;
			}

			return null;
		}

		return $serviceLocator->get(RuntimeStateProvider::class);
	}

	private function normalizeMarketApplicationLimit(mixed $limit): ?int
	{
		if (is_int($limit))
		{
			return $limit >= -1 ? $limit : null;
		}

		if (!is_string($limit) || preg_match('/^-?\d+$/D', $limit) !== 1)
		{
			return null;
		}

		$normalized = filter_var($limit, FILTER_VALIDATE_INT);

		return is_int($normalized) && $normalized >= -1 ? $normalized : null;
	}
}

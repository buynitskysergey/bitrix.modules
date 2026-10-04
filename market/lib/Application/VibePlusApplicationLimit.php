<?php

declare(strict_types=1);

namespace Bitrix\Market\Application;

use Bitrix\Bitrix24\Internal\Service\VibePlus\Communication\ValueObject\Cta;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class VibePlusApplicationLimit
{
	public const INSTALLED_LIST_URL = '/market/installed/?vibe_plus_limit=Y';

	private readonly \Closure $limitProjectionProvider;
	private readonly \Closure $countedApplicationCodesProvider;
	private readonly \Closure $upsellProjectionProvider;
	private readonly \Closure $canManageApplicationsProvider;
	private readonly \Closure $transitionStateProvider;

	public function __construct(
		?\Closure $limitProjectionProvider = null,
		?\Closure $countedApplicationCodesProvider = null,
		?\Closure $upsellProjectionProvider = null,
		?\Closure $canManageApplicationsProvider = null,
		?\Closure $transitionStateProvider = null,
	)
	{
		$this->limitProjectionProvider = $limitProjectionProvider ?? self::resolveLimitProjection(...);
		$this->countedApplicationCodesProvider
			= $countedApplicationCodesProvider ?? self::resolveCountedApplicationCodes(...);
		$this->upsellProjectionProvider = $upsellProjectionProvider ?? self::resolveUpsellProjection(...);
		$this->canManageApplicationsProvider
			= $canManageApplicationsProvider ?? InstalledListAccess::isAdmin(...);
		$this->transitionStateProvider = $transitionStateProvider ?? self::resolveTransitionState(...);
	}

	public function getProjection(): array
	{
		$limit = $this->getLimitProjection();
		$transition = $this->getTransitionState();
		$actions = [];

		if ($this->shouldProvideActions($limit, $transition))
		{
			if ($this->canManageApplications())
			{
				$actions[] = [
					'type' => 'list',
					'target' => self::INSTALLED_LIST_URL,
				];
			}

			$upsellAction = $this->getUpsellAction();
			if ($upsellAction !== null)
			{
				$actions[] = $upsellAction;
			}
		}

		return [
			'limit' => $limit,
			'transition' => $transition,
			'actions' => $actions,
		];
	}

	/**
	 * @return string[]
	 */
	public function getCountedApplicationCodes(): array
	{
		try
		{
			$codes = ($this->countedApplicationCodesProvider)();
			if (!is_array($codes))
			{
				return [];
			}

			$result = [];
			foreach ($codes as $code)
			{
				if (is_string($code) && $code !== '')
				{
					$result[$code] = $code;
				}
			}

			return array_values($result);
		}
		catch (\Throwable)
		{
			return [];
		}
	}

	private function getLimitProjection(): array
	{
		try
		{
			$projection = ($this->limitProjectionProvider)();
			$state = $projection->getState();
			$stateValue = $state instanceof \BackedEnum ? $state->value : null;
			if (!is_string($stateValue) || $stateValue === '')
			{
				return self::unknownLimitProjection();
			}

			return [
				'state' => $stateValue,
				'count' => $projection->getCount(),
				'limit' => $projection->getLimit(),
				'configuredLimit' => method_exists($projection, 'getConfiguredLimit')
					? $projection->getConfiguredLimit()
					: $projection->getLimit(),
				'exceeded' => $projection->isExceeded(),
				'installationBlocked' => $projection->isInstallationBlocked(),
			];
		}
		catch (\Throwable)
		{
			return self::unknownLimitProjection();
		}
	}

	private function getTransitionState(): array
	{
		try
		{
			$state = ($this->transitionStateProvider)();
			$active = $state['active'] ?? null;
			$endsAt = $state['endsAt'] ?? null;
			if (
				!is_bool($active)
				|| ($endsAt !== null && (!is_int($endsAt) || $endsAt <= 0))
			)
			{
				return self::inactiveTransitionState();
			}

			return [
				'active' => $active,
				'endsAt' => $active ? $endsAt : null,
			];
		}
		catch (\Throwable)
		{
			return self::inactiveTransitionState();
		}
	}

	private function shouldProvideActions(array $limit, array $transition): bool
	{
		if ($limit['installationBlocked'] === true)
		{
			return true;
		}

		return
			$transition['active'] === true
			&& $transition['endsAt'] !== null
			&& $limit['state'] === 'unlimited'
			&& is_int($limit['count'])
			&& is_int($limit['configuredLimit'])
			&& $limit['configuredLimit'] >= 0
			&& $limit['count'] > $limit['configuredLimit'];
	}

	private function getUpsellAction(): ?array
	{
		try
		{
			$projection = ($this->upsellProjectionProvider)();
			if (!is_object($projection))
			{
				return null;
			}

			$cta = $projection->getCta();
			$target = $projection->getCtaTarget();
			if (
				$cta !== 'buy'
				|| !Cta::isTargetValid($target)
			)
			{
				return null;
			}

			return [
				'type' => $cta,
				'target' => $target,
			];
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function canManageApplications(): bool
	{
		try
		{
			return ($this->canManageApplicationsProvider)() === true;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private static function resolveLimitProjection(): object
	{
		if (!Loader::includeModule('rest'))
		{
			throw new \RuntimeException('The rest module is unavailable.');
		}

		$providerClass = \Bitrix\Rest\Public\Service\VibePlusMarketApplicationLimitProvider::class;
		$serviceLocator = ServiceLocator::getInstance();
		if (!$serviceLocator->has($providerClass))
		{
			throw new \RuntimeException('The application limit provider is unavailable.');
		}

		return $serviceLocator->get($providerClass)->getProjection();
	}

	/**
	 * @return string[]
	 */
	private static function resolveCountedApplicationCodes(): array
	{
		if (!Loader::includeModule('rest'))
		{
			return [];
		}

		$providerClass = \Bitrix\Rest\Public\Service\VibePlusMarketApplicationLimitProvider::class;
		$serviceLocator = ServiceLocator::getInstance();
		if (!$serviceLocator->has($providerClass))
		{
			return [];
		}

		return $serviceLocator->get($providerClass)->getCountedApplicationCodes();
	}

	private static function resolveUpsellProjection(): ?object
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return null;
		}

		$providerClass = \Bitrix\Bitrix24\Public\Service\VibePlus\UpsellProjectionProvider::class;
		$serviceLocator = ServiceLocator::getInstance();
		if (!$serviceLocator->has($providerClass))
		{
			return null;
		}

		return $serviceLocator->get($providerClass)->getBuyProjection();
	}

	private static function resolveTransitionState(): array
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return self::inactiveTransitionState();
		}

		$providerClass = \Bitrix\Bitrix24\Public\Service\VibePlus\RuntimeStateProvider::class;
		$serviceLocator = ServiceLocator::getInstance();
		if (!$serviceLocator->has($providerClass))
		{
			return self::inactiveTransitionState();
		}

		$provider = $serviceLocator->get($providerClass);
		if (
			!method_exists($provider, 'isTransitionPeriodActive')
			|| !method_exists($provider, 'getTransitionPeriodEndAt')
		)
		{
			return self::inactiveTransitionState();
		}

		return [
			'active' => $provider->isTransitionPeriodActive(),
			'endsAt' => $provider->getTransitionPeriodEndAt(),
		];
	}

	private static function unknownLimitProjection(): array
	{
		return [
			'state' => 'unknown',
			'count' => null,
			'limit' => null,
			'configuredLimit' => null,
			'exceeded' => null,
			'installationBlocked' => null,
		];
	}

	private static function inactiveTransitionState(): array
	{
		return [
			'active' => false,
			'endsAt' => null,
		];
	}
}

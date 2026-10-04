<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Service;

use Bitrix\Crm\Integration\AI\ConfigurationDifference\ConfigurationProvider\DealFields;
use Bitrix\Crm\Integration\AI\ConfigurationDifference\ConfigurationProvider\DealStages;
use Bitrix\Crm\Integration\AI\ConfigurationDifference\Contract\ConfigurationProvider;
use Bitrix\Crm\Integration\AI\ConfigurationDifference\DifferenceCalculator;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Repository\PortalRepository;
use Bitrix\Crm\V2\Internal\Integration\Intranet\LeftMenuPresetRepository;
use Bitrix\Main\Type\DateTime;

/**
 * Tells whether the portal and the user match the audience of the CRM onboarding trigger:
 * a young CRM-preset portal whose CRM administrator has not configured CRM yet.
 *
 * Every step is a separate predicate on purpose: the trigger reacts to each rejection reason
 * with its own side effect, so a single combined answer would not be enough.
 *
 * The rules are carried over literally from the legacy condition of the aiassistant triggers,
 * which stays in place until those triggers are removed.
 *
 * @see \Bitrix\AiAssistant\Integrations\Crm\Service\CrmConditionService
 *
 * @internal
 */
class CrmSetupConditionService
{
	private const NEED_PERCENTAGE = 30;
	private const NEW_PORTAL_DAYS = 30;

	public function __construct(
		private readonly PortalRepository $portalRepository,
		private readonly DifferenceCalculator $differenceCalculator,
		private readonly LeftMenuPresetRepository $leftMenuPresetRepository,
	)
	{
	}

	/**
	 * Checks that the portal is set up for sales, i.e. uses the CRM left menu preset.
	 */
	public function isCrmPresetPortal(): bool
	{
		return $this->leftMenuPresetRepository->isCrmPreset();
	}

	/**
	 * Checks that the portal itself is younger than the onboarding window.
	 */
	public function isNewPortal(): bool
	{
		$portalStart = $this->portalRepository->getFirstUserRegisterDate();
		if ($portalStart === null)
		{
			return false;
		}

		$threshold = (new DateTime())->add('-' . self::NEW_PORTAL_DAYS . ' days');

		return $portalStart->getTimestamp() > $threshold->getTimestamp();
	}

	/**
	 * Checks that the user is allowed to configure CRM.
	 */
	public function isUserCrmAdmin(int $userId): bool
	{
		return Container::getInstance()->getUserPermissions($userId)->admin()->isCrmAdmin();
	}

	/**
	 * Checks whether CRM still looks unconfigured for the user: at least one of the tracked
	 * configuration aspects differs from the out-of-the-box state by less than the threshold.
	 */
	public function hasLowCrmConfigurationPercentage(int $userId): bool
	{
		foreach ($this->getConfigurationProviders($userId) as $provider)
		{
			if ($this->isConfiguredBelowThreshold($provider))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Providers are yielded one by one: as soon as an aspect fails the threshold, the next
	 * provider is never built, exactly as in the legacy condition.
	 *
	 * @return iterable<ConfigurationProvider>
	 */
	protected function getConfigurationProviders(int $userId): iterable
	{
		yield new DealStages(userId: $userId);
		yield new DealFields(userId: $userId);
	}

	private function isConfiguredBelowThreshold(ConfigurationProvider $provider): bool
	{
		$configuredPercentage = $this->differenceCalculator
			->calculate($provider)
			->configuredPercentage()
		;

		return $configuredPercentage < self::NEED_PERCENTAGE;
	}
}

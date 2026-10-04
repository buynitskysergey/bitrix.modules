<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Integration\Main;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UI\Spotlight;
use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;

class CatalogOnboardingSpotlight
{
	private const SPOTLIGHT_ID = 'vibecode_catalog_onboarding';
	private const LIFETIME = 2592000;
	private const POPUP_FROM_DAY_NUMBER = 3;
	private const OPTION_DELAY_ENABLED = 'onboarding_popup_delay';
	private const USER_OPTION_CATEGORY = 'vibecodeconnector';
	private const USER_OPTION_FIRST_SEEN_AT = 'catalog_onboarding_first_seen_at';

	public function __construct(
		private readonly ?AvailabilityService $availabilityService = null,
		private readonly ModuleOptions $options = new ModuleOptions(),
		private readonly ?DateTime $now = null,
	) {
	}

	public function mustBeShownForUser(int $userId): bool
	{
		if ($this->isShownForUser($userId))
		{
			return false;
		}

		if (!$this->availabilityService()->isAvailableForUser($userId))
		{
			return false;
		}

		if (!$this->availabilityService()->isReady())
		{
			return false;
		}

		if ($this->isDelayEnabled() && !$this->isDelayPassedForUser($userId))
		{
			return false;
		}

		return $this->isAvailableForUser($userId);
	}

	// Read-only gate check. Does not mark the spotlight as shown.
	public function isAvailableForUser(int $userId): bool
	{
		return $this->create()->isAvailable($userId);
	}

	public function isShownForUser(int $userId): bool
	{
		return $this->create()->isViewed($userId);
	}

	// Records that the catalog onboarding has been shown to the user.
	public function markShownForUser(int $userId): void
	{
		$this->create()->setViewDate($userId);
	}

	private function isDelayEnabled(): bool
	{
		return $this->options->get(self::OPTION_DELAY_ENABLED, 'Y') === 'Y';
	}

	private function isDelayPassedForUser(int $userId): bool
	{
		$startMoment = $this->startMomentForUser($this->firstSeenAtForUser($userId));

		return $this->currentMoment()->getTimestamp() >= $startMoment;
	}

	private function firstSeenAtForUser(int $userId): int
	{
		$storedMoment = (int)\CUserOptions::getOption(
			self::USER_OPTION_CATEGORY,
			self::USER_OPTION_FIRST_SEEN_AT,
			0,
			$userId,
		);

		if ($storedMoment > 0)
		{
			return $storedMoment;
		}

		$firstSeenAt = $this->currentMoment()->getTimestamp();
		\CUserOptions::setOption(
			self::USER_OPTION_CATEGORY,
			self::USER_OPTION_FIRST_SEEN_AT,
			$firstSeenAt,
			false,
			$userId,
		);

		return $firstSeenAt;
	}

	private function startMomentForUser(int $firstSeenAt): int
	{
		return Date::createFromTimestamp($firstSeenAt)
			->add('+' . (self::POPUP_FROM_DAY_NUMBER - 1) . ' days')
			->getTimestamp()
		;
	}

	private function currentMoment(): DateTime
	{
		return $this->now ?? new DateTime();
	}

	private function availabilityService(): AvailabilityService
	{
		return $this->availabilityService ?? ServiceLocator::getInstance()->get(AvailabilityService::class);
	}

	private function create(): Spotlight
	{
		$spotlight = new Spotlight(self::SPOTLIGHT_ID);
		$spotlight->setUserType(Spotlight::USER_TYPE_ALL);
		$spotlight->setLifetime(self::LIFETIME);

		return $spotlight;
	}
}

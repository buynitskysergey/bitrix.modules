<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Conversation;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Public\Service\Message\Conversation\Dto\ConversationSettings;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Settings, onboarding and feature flag of the conversation grid (API-04).
 *
 * This is the single owner of the stored keys of the feature: the grid, the mobile client and the
 * future web client read and write them through here, so no consumer has to know how a value is
 * spelled or what it falls back to.
 *
 * Grouping is one choice of the user shared by every client of that user, while the onboarding tour
 * is marked per platform, because one client showing the tour says nothing about the others.
 *
 * The right to see the tour at all is captured once from the candidate calculated by the first list
 * response after the release and is never revised afterwards. The list reads the candidate before
 * `previous_seen_mailbox_id` is written, then the client confirms it through the protected mutation.
 *
 * The user always comes from the server session of the caller and is never taken from a request, so
 * every write here is a write for oneself.
 */
class ConversationSettingsService
{
	public const ERROR_INVALID_VALUE = 'INVALID_VALUE';
	public const ERROR_FEATURE_UNAVAILABLE = 'FEATURE_UNAVAILABLE';
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';

	public const PLATFORM_MOBILE = 'mobile';
	public const PLATFORM_WEB = 'web';

	public const OPTION_CATEGORY = 'mail';
	public const ENABLED_OPTION = 'conversation_grid_enabled';
	public const ONBOARDING_ELIGIBLE_OPTION = 'conversation_grid_onboarding_eligible';

	private const PLATFORMS = [self::PLATFORM_MOBILE, self::PLATFORM_WEB];
	private const YES = 'Y';
	private const NO = 'N';

	/** Key of the onboarding mark of one platform. */
	public static function onboardingSeenOption(string $platform): string
	{
		return 'conversation_grid_onboarding_' . $platform . '_seen';
	}

	/**
	 * @param int $userId current user of the server session, never taken from the request
	 * @return Result `getData()` holds the fields of {@see ConversationSettings}
	 */
	public function getSettings(int $userId, string $platform): Result
	{
		$result = new Result();
		if ($userId <= 0)
		{
			return $result->addError($this->accessDeniedError());
		}
		if (!$this->isKnownPlatform($platform))
		{
			return $result->addError($this->unknownPlatformError());
		}

		return $result->setData($this->readSettings($userId, $platform)->toArray());
	}

	/**
	 * @param int $userId current user of the server session, never taken from the request
	 * @return Result `getData()` holds the stored choice
	 */
	public function setEnabled(int $userId, bool $enabled): Result
	{
		$result = new Result();
		if ($userId <= 0)
		{
			return $result->addError($this->accessDeniedError());
		}
		if (!Feature::isMessageConversationGridAvailable())
		{
			return $result->addError($this->featureUnavailableError());
		}

		$this->writeFlag($userId, self::ENABLED_OPTION, $enabled);

		return $result->setData(['enabled' => $enabled]);
	}

	/**
	 * Write-once capture of the right to see the onboarding.
	 *
	 * A stored value is never revised, whatever this call is told: by the time of the second call the
	 * trace of the earlier visit is gone, so the first answer is the only informed one and overwriting
	 * it would be irreversible for the user.
	 *
	 * @param int $userId current user of the server session, never taken from the request
	 * @param bool $hadPreviousMailbox whether the user had opened Mail before the release
	 * @return Result `getData()` holds the captured right, the stored one if there already was one
	 */
	public function captureOnboardingEligibility(int $userId, bool $hadPreviousMailbox): Result
	{
		$result = new Result();
		if ($userId <= 0)
		{
			return $result->addError($this->accessDeniedError());
		}

		$captured = $this->readFlag($userId, self::ONBOARDING_ELIGIBLE_OPTION);
		if ($captured === null)
		{
			$captured = $hadPreviousMailbox;
			$this->writeFlag($userId, self::ONBOARDING_ELIGIBLE_OPTION, $captured);
		}

		return $result->setData(['onboardingEligible' => $captured]);
	}

	/**
	 * @param int $userId current user of the server session, never taken from the request
	 * @return Result `getData()` holds the fields of {@see ConversationSettings} of that platform
	 */
	public function markOnboardingSeen(int $userId, string $platform): Result
	{
		$result = new Result();
		if ($userId <= 0)
		{
			return $result->addError($this->accessDeniedError());
		}
		if (!$this->isKnownPlatform($platform))
		{
			return $result->addError($this->unknownPlatformError());
		}
		if (!Feature::isMessageConversationGridAvailable())
		{
			return $result->addError($this->featureUnavailableError());
		}

		$this->writeFlag($userId, self::onboardingSeenOption($platform), true);

		return $result->setData($this->readSettings($userId, $platform)->toArray());
	}

	/**
	 * Settings of one known platform, for a consumer that already holds the user of its session and
	 * needs no request contract around the answer. A platform that came from a request is checked by
	 * {@see self::getSettings()} first.
	 */
	public function readSettings(int $userId, string $platform): ConversationSettings
	{
		return new ConversationSettings(
			Feature::isMessageConversationGridAvailable(),
			$this->readFlag($userId, self::ENABLED_OPTION) ?? true,
			$this->readFlag($userId, self::ONBOARDING_ELIGIBLE_OPTION) ?? false,
			$this->readFlag($userId, self::onboardingSeenOption($platform)) ?? false,
		);
	}

	private function isKnownPlatform(string $platform): bool
	{
		return in_array($platform, self::PLATFORMS, true);
	}

	/** Stored mark of the user, or null while the user has none. */
	private function readFlag(int $userId, string $name): ?bool
	{
		return match (\CUserOptions::getOption(self::OPTION_CATEGORY, $name, null, $userId))
		{
			self::YES => true,
			self::NO => false,
			default => null,
		};
	}

	private function writeFlag(int $userId, string $name, bool $value): void
	{
		\CUserOptions::setOption(
			self::OPTION_CATEGORY,
			$name,
			$value ? self::YES : self::NO,
			false,
			$userId,
		);
	}

	private function accessDeniedError(): Error
	{
		return new Error('There is no current user.', self::ERROR_ACCESS_DENIED);
	}

	private function unknownPlatformError(): Error
	{
		return new Error('The platform is unknown.', self::ERROR_INVALID_VALUE);
	}

	private function featureUnavailableError(): Error
	{
		return new Error('The conversation grid is not available.', self::ERROR_FEATURE_UNAVAILABLE);
	}
}

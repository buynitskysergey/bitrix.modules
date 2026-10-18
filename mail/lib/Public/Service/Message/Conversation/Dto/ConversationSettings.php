<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Conversation\Dto;

/**
 * Settings of the conversation grid as one platform sees them (API-04).
 *
 * Three values of different owners meet here. `featureAvailable` is a decision of the portal and
 * outranks the rest. `enabled` is the choice of the user and is shared by every client of that user.
 * `onboardingEligible` and `onboardingSeen` belong to the tour of one platform: a tour shown on the
 * phone says nothing about the browser.
 */
final class ConversationSettings
{
	public function __construct(
		public readonly bool $featureAvailable,
		public readonly bool $enabled,
		public readonly bool $onboardingEligible,
		public readonly bool $onboardingSeen,
	)
	{
	}

	/** Whether the grid really groups: the flag of the portal outranks the choice of the user. */
	public function isGroupingActive(): bool
	{
		return $this->featureAvailable && $this->enabled;
	}

	public function toArray(): array
	{
		return [
			'featureAvailable' => $this->featureAvailable,
			'enabled' => $this->enabled,
			'onboardingEligible' => $this->onboardingEligible,
			'onboardingSeen' => $this->onboardingSeen,
		];
	}
}

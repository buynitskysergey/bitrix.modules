<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\ValueObject;

/**
 * Stores raw REST usage counters related to Vibe+ capabilities.
 */
final readonly class VibePlusUsageSnapshot
{
	public function __construct(
		private int $userWebhookCount,
		private int $userIntegrationCount,
		private int $marketApplicationCount,
		private int $vibecodeWebhookCount,
		private int $vibecodeApplicationCount,
		private int $developerKeyCount,
	)
	{
	}

	public function getUserWebhookCount(): int
	{
		return $this->userWebhookCount;
	}

	public function getUserIntegrationCount(): int
	{
		return $this->userIntegrationCount;
	}

	public function getMarketApplicationCount(): int
	{
		return $this->marketApplicationCount;
	}

	public function getVibecodeWebhookCount(): int
	{
		return $this->vibecodeWebhookCount;
	}

	public function getVibecodeApplicationCount(): int
	{
		return $this->vibecodeApplicationCount;
	}

	public function getDeveloperKeyCount(): int
	{
		return $this->developerKeyCount;
	}

	public function hasUserRestUsage(): bool
	{
		return $this->userWebhookCount > 0 || $this->userIntegrationCount > 0;
	}

	public function hasVibecodeUsage(): bool
	{
		return $this->vibecodeWebhookCount > 0
			|| $this->vibecodeApplicationCount > 0
			|| $this->developerKeyCount > 0;
	}
}

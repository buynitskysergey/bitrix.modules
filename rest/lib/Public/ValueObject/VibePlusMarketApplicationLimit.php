<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\ValueObject;

use Bitrix\Rest\Public\Enum\VibePlus\MarketApplicationLimitState;

final readonly class VibePlusMarketApplicationLimit
{
	private function __construct(
		private MarketApplicationLimitState $state,
		private ?int $count,
		private ?int $limit,
		private ?bool $exceeded,
		private ?bool $installationBlocked,
		private ?int $configuredLimit,
	)
	{
	}

	public static function finite(int $count, int $limit): self
	{
		if ($count < 0 || $limit < 0)
		{
			throw new \InvalidArgumentException('Count and finite limit must be non-negative.');
		}

		return new self(
			MarketApplicationLimitState::Finite,
			$count,
			$limit,
			$count > $limit,
			$count >= $limit,
			$limit,
		);
	}

	public static function unlimited(int $count, ?int $configuredLimit = -1): self
	{
		if ($count < 0 || ($configuredLimit !== null && $configuredLimit < -1))
		{
			throw new \InvalidArgumentException('Count and configured limit have invalid values.');
		}

		return new self(
			MarketApplicationLimitState::Unlimited,
			$count,
			null,
			false,
			false,
			$configuredLimit,
		);
	}

	public static function notApplicable(?int $count = null): self
	{
		self::validateOptionalCount($count);

		return new self(
			MarketApplicationLimitState::NotApplicable,
			$count,
			null,
			null,
			null,
			null,
		);
	}

	public static function unknown(?int $count = null): self
	{
		self::validateOptionalCount($count);

		return new self(
			MarketApplicationLimitState::Unknown,
			$count,
			null,
			null,
			null,
			null,
		);
	}

	public function getState(): MarketApplicationLimitState
	{
		return $this->state;
	}

	public function getCount(): ?int
	{
		return $this->count;
	}

	public function getLimit(): ?int
	{
		return $this->limit;
	}

	public function isExceeded(): ?bool
	{
		return $this->exceeded;
	}

	public function isInstallationBlocked(): ?bool
	{
		return $this->installationBlocked;
	}

	public function getConfiguredLimit(): ?int
	{
		return $this->configuredLimit;
	}

	private static function validateOptionalCount(?int $count): void
	{
		if ($count !== null && $count < 0)
		{
			throw new \InvalidArgumentException('Count must be non-negative.');
		}
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Strategy;

use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Exception\SubscriptionStrategyUnavailableException;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Portal;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\ActivationStrategy;

final class SubscriptionMarketActivationStrategy implements ActivationStrategy
{
	private const REGIONS = ['ru', 'by'];

	private readonly Portal $portal;

	public function __construct(?Portal $portal = null)
	{
		$this->portal = $portal ?? new Portal();
	}

	public function isApplicable(): bool
	{
		return $this->portal->isCloudPortal() && in_array($this->resolveRegion(), self::REGIONS, true);
	}

	public function isActivated(): bool
	{
		throw new SubscriptionStrategyUnavailableException();
	}

	public function findExpireDate(): ?DateTime
	{
		return null;
	}

	public function activate(int $days): void
	{
		throw new SubscriptionStrategyUnavailableException();
	}

	public function hasGrantedTrial(): bool
	{
		return false;
	}

	private function resolveRegion(): string
	{
		return mb_strtolower(trim($this->portal->getRegion()));
	}
}

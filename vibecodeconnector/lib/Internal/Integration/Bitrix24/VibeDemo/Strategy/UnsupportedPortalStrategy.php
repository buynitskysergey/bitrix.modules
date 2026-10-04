<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Strategy;

use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Exception\NotCloudPortalException;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Exception\PortalTariffNotSupportedException;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Portal;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\ActivationStrategy;

final class UnsupportedPortalStrategy implements ActivationStrategy
{
	private readonly Portal $portal;

	public function __construct(?Portal $portal = null)
	{
		$this->portal = $portal ?? new Portal();
	}

	public function isApplicable(): bool
	{
		return true;
	}

	public function isActivated(): bool
	{
		throw $this->refusal();
	}

	public function findExpireDate(): ?DateTime
	{
		return null;
	}

	public function activate(int $days): void
	{
		throw $this->refusal();
	}

	public function hasGrantedTrial(): bool
	{
		return false;
	}

	private function refusal(): \Throwable
	{
		return $this->portal->isCloudPortal()
			? new PortalTariffNotSupportedException()
			: new NotCloudPortalException();
	}
}

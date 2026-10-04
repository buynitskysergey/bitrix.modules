<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Licensing;

use Bitrix\Vibecodeconnector\Internal\Exception\ProvisioningFailedException;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibePlusPolicy;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\ProvisioningAccessPolicy;

final class ProvisioningAccessGate
{
	public const ERROR_CODE = 'FEATURE_NOT_AVAILABLE_ON_CURRENT_PLAN';
	public const ERROR_MESSAGE = 'Feature is not available on the current plan.';

	public function __construct(
		private readonly VibePlusPolicy $vibePlusPolicy = new VibePlusPolicy(),
		private readonly ?ProvisioningAccessPolicy $restProvisioningPolicy = null,
	)
	{
	}

	public function ensureVibeCodeAvailable(): void
	{
		if ($this->vibePlusPolicy->getAvailability() === false)
		{
			throw new ProvisioningFailedException(self::ERROR_MESSAGE, self::ERROR_CODE);
		}
	}

	public function ensureRestProvisioningAvailable(): void
	{
		$this->ensureVibeCodeAvailable();
		($this->restProvisioningPolicy ?? new ProvisioningAccessPolicy())->ensureAvailable();
	}
}

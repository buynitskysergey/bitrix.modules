<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Provisioning;

use Bitrix\Vibecodeconnector\Internal\Exception\ProvisioningFailedException;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibePlusPolicy;

final class VibeCodeAccessGuard
{
	public const ERROR_CODE = 'FEATURE_NOT_AVAILABLE_ON_CURRENT_PLAN';
	public const ERROR_MESSAGE = 'Feature is not available on the current plan.';

	public function __construct(
		private readonly VibePlusPolicy $policy = new VibePlusPolicy(),
	)
	{}

	public function ensureAvailable(): void
	{
		if ($this->policy->getAvailability() === false)
		{
			throw new ProvisioningFailedException(self::ERROR_MESSAGE, self::ERROR_CODE);
		}
	}
}

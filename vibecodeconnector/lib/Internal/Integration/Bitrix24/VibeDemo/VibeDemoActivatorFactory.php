<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo;

use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Strategy\PaidTariffRegionActivationStrategy;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Strategy\SubscriptionMarketActivationStrategy;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Strategy\UnsupportedPortalStrategy;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Strategy\VibePlusEditionTrialStrategy;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Strategy\VibePlusFeatureTrialStrategy;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Activator;

final class VibeDemoActivatorFactory
{
	public static function create(?Portal $portal = null): Activator
	{
		$portal ??= new Portal();

		return new Activator(
			new SubscriptionMarketActivationStrategy($portal),
			new PaidTariffRegionActivationStrategy($portal),
			new VibePlusEditionTrialStrategy($portal),
			new VibePlusFeatureTrialStrategy($portal),
			new UnsupportedPortalStrategy($portal),
		);
	}
}

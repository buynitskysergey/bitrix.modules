<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Exception;

use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception\VibeDemoException;

final class PortalTariffNotSupportedException extends VibeDemoException
{
	protected const MESSAGE_ID = 'VIBECODECONNECTOR_VIBE_DEMO_PORTAL_TARIFF_NOT_SUPPORTED';
	public const ERROR_CODE = 'VIBE_DEMO_PORTAL_TARIFF_NOT_SUPPORTED';
}

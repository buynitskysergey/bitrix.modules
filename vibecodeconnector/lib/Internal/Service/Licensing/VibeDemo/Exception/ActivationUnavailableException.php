<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception;

final class ActivationUnavailableException extends VibeDemoException
{
	protected const MESSAGE_ID = 'VIBECODECONNECTOR_VIBE_DEMO_ACTIVATION_UNAVAILABLE';
	public const ERROR_CODE = 'VIBE_DEMO_ACTIVATION_UNAVAILABLE';
}

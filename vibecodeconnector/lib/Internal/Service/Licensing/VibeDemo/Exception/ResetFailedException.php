<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception;

final class ResetFailedException extends VibeDemoException
{
	protected const MESSAGE_ID = 'VIBECODECONNECTOR_VIBE_DEMO_RESET_FAILED';
	public const ERROR_CODE = 'VIBE_DEMO_RESET_FAILED';
}

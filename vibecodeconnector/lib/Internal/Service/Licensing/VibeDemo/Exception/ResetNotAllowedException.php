<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception;

final class ResetNotAllowedException extends VibeDemoException
{
	protected const MESSAGE_ID = 'VIBECODECONNECTOR_VIBE_DEMO_RESET_NOT_ALLOWED';
	public const ERROR_CODE = 'VIBE_DEMO_RESET_NOT_ALLOWED';
}

<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception;

final class TrialDurationInvalidException extends VibeDemoException
{
	protected const MESSAGE_ID = 'VIBECODECONNECTOR_VIBE_DEMO_TRIAL_DURATION_INVALID';
	public const ERROR_CODE = 'VIBE_DEMO_DAYS_INVALID';
}

<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception;

final class TrialAlreadyUsedException extends VibeDemoException
{
	protected const MESSAGE_ID = 'VIBECODECONNECTOR_VIBE_DEMO_TRIAL_ALREADY_USED';
	public const ERROR_CODE = 'VIBE_DEMO_TARIFF_TRIAL_USED';
}

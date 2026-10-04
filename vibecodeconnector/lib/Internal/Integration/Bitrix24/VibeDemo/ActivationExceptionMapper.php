<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo;

use Bitrix\Bitrix24\Public\Command\Licensing\VibePlus\ActivateDemoTariffCommand;
use Bitrix\Bitrix24\Public\Command\Licensing\VibePlus\ActivateTrialFeaturesCommand;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception\ActivationFailedException;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception\TrialDurationInvalidException;

final class ActivationExceptionMapper
{
	public static function map(\Throwable $exception): \Throwable
	{
		if (!$exception instanceof CommandValidationException)
		{
			return $exception;
		}

		if (self::hasDaysValidationError($exception))
		{
			return new TrialDurationInvalidException($exception);
		}

		return new ActivationFailedException($exception);
	}

	private static function hasDaysValidationError(CommandValidationException $exception): bool
	{
		$daysErrorCodes = [
			ActivateDemoTariffCommand::ERROR_DAYS_NOT_POSITIVE,
			ActivateDemoTariffCommand::ERROR_DAYS_OUT_OF_RANGE,
			ActivateTrialFeaturesCommand::ERROR_DAYS_NOT_POSITIVE,
			ActivateTrialFeaturesCommand::ERROR_DAYS_OUT_OF_RANGE,
		];

		foreach ($exception->getValidationErrors() as $error)
		{
			if (in_array((string)$error->getCode(), $daysErrorCodes, true))
			{
				return true;
			}
		}

		return false;
	}
}

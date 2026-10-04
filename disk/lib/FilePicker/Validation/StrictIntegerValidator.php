<?php

declare(strict_types=1);

namespace Bitrix\Disk\FilePicker\Validation;

use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Main\Validation\Validator\ValidatorInterface;

final class StrictIntegerValidator implements ValidatorInterface
{
	public function validate(mixed $value): ValidationResult
	{
		$result = new ValidationResult();
		if ($value !== null && !is_int($value))
		{
			$result->addError(new ValidationError(
				'Value must be an integer.',
				failedValidator: $this,
			));
		}

		return $result;
	}
}

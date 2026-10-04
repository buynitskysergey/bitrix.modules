<?php

declare(strict_types=1);

namespace Bitrix\Disk\FilePicker\Validation;

use Attribute;
use Bitrix\Main\Validation\Rule\AbstractPropertyValidationAttribute;
use Bitrix\Main\Validation\Validator\ValidatorInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class StrictInteger extends AbstractPropertyValidationAttribute
{
	/**
	 * @return ValidatorInterface[]
	 */
	protected function getValidators(): array
	{
		return [
			new StrictIntegerValidator(),
		];
	}
}

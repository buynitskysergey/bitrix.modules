<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\Validation;

trait NumericValidationTrait
{
	use ExclusiveMaximumValidationTrait;
	use ExclusiveMinimumValidationTrait;
	use MaximumValidationTrait;
	use MinimumValidationTrait;
	use MultipleOfValidationTrait;

	protected function getNumericValidationSchema(): array
	{
		return [
			...$this->getMinimumValidationSchema(),
			...$this->getMaximumValidationSchema(),
			...$this->getExclusiveMinimumValidationSchema(),
			...$this->getExclusiveMaximumValidationSchema(),
			...$this->getMultipleOfValidationSchema(),
		];
	}
}

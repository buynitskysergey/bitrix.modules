<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties;

final class NumberProperty extends AbstractProperty
{
	use Validation\NumericValidationTrait;

	public function getType(): string
	{
		return 'number';
	}

	public function toArray(): array
	{
		return [
			...parent::toArray(),
			...$this->getNumericValidationSchema(),
		];
	}
}

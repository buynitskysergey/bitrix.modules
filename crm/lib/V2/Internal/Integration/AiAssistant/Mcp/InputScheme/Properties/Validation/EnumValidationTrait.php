<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\Validation;

trait EnumValidationTrait
{
	private bool $isEnumSet = false;
	private array $enum = [];

	/**
	 * Limits the value to one of the listed JSON Schema enum values.
	 *
	 * For nullable properties, include null in the enum list if null is allowed as a value.
	 *
	 * @param array $enum
	 * @return $this
	 */
	public function setEnum(array $enum): static
	{
		if (empty($enum))
		{
			throw new \InvalidArgumentException('JSON Schema enum must contain at least one value.');
		}

		$this->enum = $enum;
		$this->isEnumSet = true;

		return $this;
	}

	protected function getEnumValidationSchema(): array
	{
		if (!$this->isEnumSet)
		{
			return [];
		}

		return ['enum' => $this->enum];
	}
}

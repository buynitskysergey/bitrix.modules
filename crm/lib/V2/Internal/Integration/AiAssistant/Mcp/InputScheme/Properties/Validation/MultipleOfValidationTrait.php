<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\Validation;

trait MultipleOfValidationTrait
{
	private int|float|null $multipleOf = null;

	/**
	 * Requires the numeric value to be a multiple of the given factor.
	 *
	 * @param int|float $multipleOf
	 * @return $this
	 */
	public function setMultipleOf(int|float $multipleOf): static
	{
		if ($multipleOf <= 0)
		{
			throw new \InvalidArgumentException('JSON Schema multipleOf must be greater than 0.');
		}

		$this->multipleOf = $multipleOf;

		return $this;
	}

	protected function getMultipleOfValidationSchema(): array
	{
		if ($this->multipleOf === null)
		{
			return [];
		}

		return ['multipleOf' => $this->multipleOf];
	}
}

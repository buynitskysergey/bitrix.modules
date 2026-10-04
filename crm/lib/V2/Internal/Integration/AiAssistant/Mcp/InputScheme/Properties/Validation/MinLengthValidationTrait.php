<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\Validation;

trait MinLengthValidationTrait
{
	private ?int $minLength = null;

	/**
	 * Sets the minimum allowed string length.
	 *
	 * @param int $minLength
	 * @return $this
	 */
	public function setMinLength(int $minLength): static
	{
		if ($minLength < 0)
		{
			throw new \InvalidArgumentException('JSON Schema minLength must be greater than or equal to 0.');
		}

		$this->minLength = $minLength;

		return $this;
	}

	protected function getMinLengthValidationSchema(): array
	{
		if ($this->minLength === null)
		{
			return [];
		}

		return ['minLength' => $this->minLength];
	}
}

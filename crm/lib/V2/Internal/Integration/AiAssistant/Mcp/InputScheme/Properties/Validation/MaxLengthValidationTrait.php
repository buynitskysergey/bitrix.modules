<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\Validation;

trait MaxLengthValidationTrait
{
	private ?int $maxLength = null;

	/**
	 * Sets the maximum allowed string length.
	 *
	 * @param int $maxLength
	 * @return $this
	 */
	public function setMaxLength(int $maxLength): static
	{
		if ($maxLength < 0)
		{
			throw new \InvalidArgumentException('JSON Schema maxLength must be greater than or equal to 0.');
		}

		$this->maxLength = $maxLength;

		return $this;
	}

	protected function getMaxLengthValidationSchema(): array
	{
		if ($this->maxLength === null)
		{
			return [];
		}

		return ['maxLength' => $this->maxLength];
	}
}

<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\Validation;

trait MaximumValidationTrait
{
	private int|float|null $maximum = null;

	/**
	 * Sets the inclusive upper numeric bound.
	 *
	 * @param int|float $maximum
	 * @return $this
	 */
	public function setMaximum(int|float $maximum): static
	{
		$this->maximum = $maximum;

		return $this;
	}

	protected function getMaximumValidationSchema(): array
	{
		if ($this->maximum === null)
		{
			return [];
		}

		return ['maximum' => $this->maximum];
	}
}

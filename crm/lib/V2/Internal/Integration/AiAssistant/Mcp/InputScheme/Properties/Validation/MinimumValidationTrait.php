<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\Validation;

trait MinimumValidationTrait
{
	private int|float|null $minimum = null;

	/**
	 * Sets the inclusive lower numeric bound.
	 *
	 * @param int|float $minimum
	 * @return $this
	 */
	public function setMinimum(int|float $minimum): static
	{
		$this->minimum = $minimum;

		return $this;
	}

	protected function getMinimumValidationSchema(): array
	{
		if ($this->minimum === null)
		{
			return [];
		}

		return ['minimum' => $this->minimum];
	}
}

<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\Validation;

trait ExclusiveMaximumValidationTrait
{
	private int|float|null $exclusiveMaximum = null;

	/**
	 * Sets the exclusive upper numeric bound.
	 *
	 * @param int|float $exclusiveMaximum
	 * @return $this
	 */
	public function setExclusiveMaximum(int|float $exclusiveMaximum): static
	{
		$this->exclusiveMaximum = $exclusiveMaximum;

		return $this;
	}

	protected function getExclusiveMaximumValidationSchema(): array
	{
		if ($this->exclusiveMaximum === null)
		{
			return [];
		}

		return ['exclusiveMaximum' => $this->exclusiveMaximum];
	}
}

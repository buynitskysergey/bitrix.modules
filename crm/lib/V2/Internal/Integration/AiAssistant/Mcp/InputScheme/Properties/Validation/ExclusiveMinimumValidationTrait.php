<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\Validation;

trait ExclusiveMinimumValidationTrait
{
	private int|float|null $exclusiveMinimum = null;

	/**
	 * Sets the exclusive lower numeric bound.
	 *
	 * @param int|float $exclusiveMinimum
	 * @return $this
	 */
	public function setExclusiveMinimum(int|float $exclusiveMinimum): static
	{
		$this->exclusiveMinimum = $exclusiveMinimum;

		return $this;
	}

	protected function getExclusiveMinimumValidationSchema(): array
	{
		if ($this->exclusiveMinimum === null)
		{
			return [];
		}

		return ['exclusiveMinimum' => $this->exclusiveMinimum];
	}
}

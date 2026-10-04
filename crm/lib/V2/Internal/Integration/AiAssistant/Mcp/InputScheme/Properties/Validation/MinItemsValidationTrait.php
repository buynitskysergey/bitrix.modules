<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\Validation;

trait MinItemsValidationTrait
{
	private ?int $minItems = null;

	/**
	 * Sets the minimum allowed number of array items.
	 *
	 * @param int $minItems
	 * @return $this
	 */
	public function setMinItems(int $minItems): static
	{
		if ($minItems < 0)
		{
			throw new \InvalidArgumentException('JSON Schema minItems must be greater than or equal to 0.');
		}

		$this->minItems = $minItems;

		return $this;
	}

	protected function getMinItemsValidationSchema(): array
	{
		if ($this->minItems === null)
		{
			return [];
		}

		return ['minItems' => $this->minItems];
	}
}

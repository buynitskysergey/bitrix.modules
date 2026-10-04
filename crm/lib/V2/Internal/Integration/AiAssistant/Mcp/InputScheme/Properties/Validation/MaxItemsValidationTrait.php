<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\Validation;

trait MaxItemsValidationTrait
{
	private ?int $maxItems = null;

	/**
	 * Sets the maximum allowed number of array items.
	 *
	 * @param int $maxItems
	 * @return $this
	 */
	public function setMaxItems(int $maxItems): static
	{
		if ($maxItems < 0)
		{
			throw new \InvalidArgumentException('JSON Schema maxItems must be greater than or equal to 0.');
		}

		$this->maxItems = $maxItems;

		return $this;
	}

	protected function getMaxItemsValidationSchema(): array
	{
		if ($this->maxItems === null)
		{
			return [];
		}

		return ['maxItems' => $this->maxItems];
	}
}

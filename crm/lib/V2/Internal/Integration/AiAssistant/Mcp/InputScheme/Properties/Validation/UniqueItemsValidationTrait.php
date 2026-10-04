<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\Validation;

trait UniqueItemsValidationTrait
{
	private ?bool $uniqueItems = null;

	/**
	 * Requires array items to be unique when set to true.
	 *
	 * @param bool $uniqueItems
	 * @return $this
	 */
	public function setUniqueItems(bool $uniqueItems): static
	{
		$this->uniqueItems = $uniqueItems;

		return $this;
	}

	protected function getUniqueItemsValidationSchema(): array
	{
		if ($this->uniqueItems === null)
		{
			return [];
		}

		return ['uniqueItems' => $this->uniqueItems];
	}
}

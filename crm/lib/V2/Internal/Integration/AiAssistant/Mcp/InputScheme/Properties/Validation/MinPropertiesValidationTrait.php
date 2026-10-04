<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\Validation;

trait MinPropertiesValidationTrait
{
	private ?int $minProperties = null;

	/**
	 * Sets the minimum allowed number of object properties.
	 *
	 * @param int $minProperties
	 * @return $this
	 */
	public function setMinProperties(int $minProperties): static
	{
		if ($minProperties < 0)
		{
			throw new \InvalidArgumentException('JSON Schema minProperties must be greater than or equal to 0.');
		}

		$this->minProperties = $minProperties;

		return $this;
	}

	protected function getMinPropertiesValidationSchema(): array
	{
		if ($this->minProperties === null)
		{
			return [];
		}

		return ['minProperties' => $this->minProperties];
	}
}

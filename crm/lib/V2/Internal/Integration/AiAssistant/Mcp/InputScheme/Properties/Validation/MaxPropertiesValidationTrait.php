<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\Validation;

trait MaxPropertiesValidationTrait
{
	private ?int $maxProperties = null;

	/**
	 * Sets the maximum allowed number of object properties.
	 *
	 * @param int $maxProperties
	 * @return $this
	 */
	public function setMaxProperties(int $maxProperties): static
	{
		if ($maxProperties < 0)
		{
			throw new \InvalidArgumentException('JSON Schema maxProperties must be greater than or equal to 0.');
		}

		$this->maxProperties = $maxProperties;

		return $this;
	}

	protected function getMaxPropertiesValidationSchema(): array
	{
		if ($this->maxProperties === null)
		{
			return [];
		}

		return ['maxProperties' => $this->maxProperties];
	}
}

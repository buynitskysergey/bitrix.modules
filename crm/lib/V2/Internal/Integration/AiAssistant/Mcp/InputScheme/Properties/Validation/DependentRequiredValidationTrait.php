<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\Validation;

trait DependentRequiredValidationTrait
{
	private ?array $dependentRequired = null;

	/**
	 * Sets property dependencies as property name to required property names map.
	 *
	 * @param array $dependentRequired
	 * @return $this
	 */
	public function setDependentRequired(array $dependentRequired): static
	{
		if (empty($dependentRequired))
		{
			throw new \InvalidArgumentException('JSON Schema dependentRequired must contain at least one dependency.');
		}

		$this->dependentRequired = $dependentRequired;

		return $this;
	}

	protected function getDependentRequiredValidationSchema(): array
	{
		if ($this->dependentRequired === null)
		{
			return [];
		}

		return ['dependentRequired' => $this->dependentRequired];
	}
}

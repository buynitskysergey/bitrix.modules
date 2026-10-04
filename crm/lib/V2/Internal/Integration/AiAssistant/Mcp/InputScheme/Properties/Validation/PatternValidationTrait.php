<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\Validation;

trait PatternValidationTrait
{
	private ?string $pattern = null;

	/**
	 * Sets the regular expression pattern the string value must match.
	 *
	 * @param string $pattern
	 * @return $this
	 */
	public function setPattern(string $pattern): static
	{
		$this->pattern = $pattern;

		return $this;
	}

	protected function getPatternValidationSchema(): array
	{
		if ($this->pattern === null)
		{
			return [];
		}

		return ['pattern' => $this->pattern];
	}
}

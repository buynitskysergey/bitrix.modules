<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\Validation;

trait ConstValidationTrait
{
	private bool $isConstSet = false;
	private mixed $const = null;

	/**
	 * Limits the value to exactly one JSON Schema const value.
	 *
	 * @param mixed $const
	 * @return $this
	 */
	public function setConst(mixed $const): static
	{
		$this->const = $const;
		$this->isConstSet = true;

		return $this;
	}

	protected function getConstValidationSchema(): array
	{
		if (!$this->isConstSet)
		{
			return [];
		}

		return ['const' => $this->const];
	}
}

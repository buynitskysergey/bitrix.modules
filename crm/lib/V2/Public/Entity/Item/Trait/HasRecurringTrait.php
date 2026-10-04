<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\Trait;

trait HasRecurringTrait
{
	private ?bool $isRecurring = null;

	public function getIsRecurring(): ?bool
	{
		return $this->isRecurring;
	}

	public function setIsRecurring(?bool $isRecurring): static
	{
		$this->isRecurring = $isRecurring;
		$this->markChanged(self::isRecurring);

		return $this;
	}

	protected function internalSetRecurringField(string $fieldName, mixed $value): bool
	{
		if ($fieldName !== self::isRecurring)
		{
			return false;
		}

		$this->isRecurring = $value;

		return true;
	}
}

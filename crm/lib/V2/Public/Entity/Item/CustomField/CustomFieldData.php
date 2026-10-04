<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\CustomField;

final readonly class CustomFieldData
{
	public function __construct(
		private mixed $value = null,
		private ?string $formattedValue = null,
		private mixed $extra = null,
	)
	{
	}

	public function getValue(): mixed
	{
		return $this->value;
	}

	public function getFormattedValue(): ?string
	{
		return $this->formattedValue;
	}

	public function getExtra(): mixed
	{
		return $this->extra;
	}
}

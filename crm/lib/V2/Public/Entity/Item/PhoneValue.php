<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

class PhoneValue extends AbstractMultifieldValue
{
	public function __construct(
		string $valueType,
		string $value,
		?int $id = null,
		private readonly ?string $countryCode = null,
	)
	{
		parent::__construct($valueType, $value, $id);
	}

	public function getCountryCode(): ?string
	{
		return $this->countryCode;
	}
}

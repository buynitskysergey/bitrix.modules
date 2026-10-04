<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\CustomField;

final readonly class Money
{
	public function __construct(
		private ?float $amount = null,
		private ?string $currencyId = null,
		private ?string $currencyFullName = null,
	)
	{
	}

	public function getAmount(): ?float
	{
		return $this->amount;
	}

	public function getCurrencyId(): ?string
	{
		return $this->currencyId;
	}

	public function getCurrencyFullName(): ?string
	{
		return $this->currencyFullName;
	}
}

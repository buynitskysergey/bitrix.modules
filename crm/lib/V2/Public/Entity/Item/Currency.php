<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

use Bitrix\Main\Type\DateTime;

final readonly class Currency
{
	public function __construct(
		private string $id,
		private ?string $fullName = null,
		private ?string $formatString = null,
		private ?string $decPoint = null,
		private ?string $thousandsSep = null,
		private ?int $decimals = null,
		private ?bool $hideZero = null,
		private ?float $amount = null,
		private ?int $amountCount = null,
		private bool $base = false,
		private ?int $sort = null,
		private ?string $numericCode = null,
		private ?string $languageId = null,
		private ?DateTime $createdTime = null,
		private ?DateTime $updatedTime = null,
		private ?int $createdById = null,
		private ?int $updatedById = null,
	)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getFullName(): ?string
	{
		return $this->fullName;
	}

	public function getFormatString(): ?string
	{
		return $this->formatString;
	}

	public function getDecPoint(): ?string
	{
		return $this->decPoint;
	}

	public function getThousandsSep(): ?string
	{
		return $this->thousandsSep;
	}

	public function getDecimals(): ?int
	{
		return $this->decimals;
	}

	public function getHideZero(): ?bool
	{
		return $this->hideZero;
	}

	public function getAmount(): ?float
	{
		return $this->amount;
	}

	public function getAmountCount(): ?int
	{
		return $this->amountCount;
	}

	public function isBase(): bool
	{
		return $this->base;
	}

	public function getSort(): ?int
	{
		return $this->sort;
	}

	public function getNumericCode(): ?string
	{
		return $this->numericCode;
	}

	public function getLanguageId(): ?string
	{
		return $this->languageId;
	}

	public function getCreatedTime(): ?DateTime
	{
		return $this->createdTime;
	}

	public function getUpdatedTime(): ?DateTime
	{
		return $this->updatedTime;
	}

	public function getCreatedById(): ?int
	{
		return $this->createdById;
	}

	public function getUpdatedById(): ?int
	{
		return $this->updatedById;
	}
}

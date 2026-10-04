<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasCompanyInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasContactBindingsInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasBeginCloseDatesInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasCategoriesInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasProductsInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasStagesInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasCategoriesTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasCompanyTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasContactBindingsTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasMyCompanyTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasProductsTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasRecurringTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasStagesTrait;
use Bitrix\Crm\V2\Public\EntityType;

final class SmartInvoice extends Item implements
	HasProductsInterface,
	HasContactBindingsInterface,
	HasCompanyInterface,
	HasStagesInterface,
	HasBeginCloseDatesInterface,
	HasCategoriesInterface
{
	public const accountNumber = 'accountNumber';
	public const locationId = 'locationId';
	public const isRecurring = 'isRecurring';

	use HasStagesTrait;
	use HasProductsTrait;
	use HasContactBindingsTrait;
	use HasCompanyTrait;
	use HasCategoriesTrait;
	use HasMyCompanyTrait;
	use HasRecurringTrait;

	private ?string $accountNumber = null;
	private ?int $locationId = null;

	public function getEntityType(): EntityType
	{
		return EntityType::smartInvoice();
	}

	public function getAccountNumber(): ?string
	{
		return $this->accountNumber;
	}

	public function setAccountNumber(?string $accountNumber): static
	{
		$this->accountNumber = $accountNumber;
		$this->markChanged(self::accountNumber);

		return $this;
	}

	public function getLocationId(): ?int
	{
		return $this->locationId;
	}

	public function setLocationId(?int $locationId): static
	{
		$this->locationId = $locationId;
		$this->markChanged(self::locationId);

		return $this;
	}

	public function internalSet(string $fieldName, mixed $value): static
	{
		if ($this->internalSetStageField($fieldName, $value))
		{
			return $this;
		}
		if ($this->internalSetProductField($fieldName, $value))
		{
			return $this;
		}
		if ($this->internalSetContactBindingField($fieldName, $value))
		{
			return $this;
		}
		if ($this->internalSetCompanyField($fieldName, $value))
		{
			return $this;
		}
		if ($this->internalSetCategoryField($fieldName, $value))
		{
			return $this;
		}
		if ($this->internalSetMyCompanyField($fieldName, $value))
		{
			return $this;
		}
		if ($this->internalSetRecurringField($fieldName, $value))
		{
			return $this;
		}

		match ($fieldName)
		{
			self::accountNumber => $this->accountNumber = $value,
			self::locationId => $this->locationId = $value,
			default => parent::internalSet($fieldName, $value),
		};

		return $this;
	}
}

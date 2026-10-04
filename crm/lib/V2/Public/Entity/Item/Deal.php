<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasBeginCloseDatesInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasCategoriesInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasCompanyInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasContactBindingsInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasProductsInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasStagesInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasCategoriesTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasCompanyTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasContactBindingsTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasMyCompanyTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasProductsTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasStagesTrait;
use Bitrix\Crm\V2\Public\EntityType;

final class Deal extends Item implements
	HasCategoriesInterface,
	HasContactBindingsInterface,
	HasCompanyInterface,
	HasStagesInterface,
	HasBeginCloseDatesInterface,
	HasProductsInterface
{
	public const typeId = 'typeId';
	public const probability = 'probability';
	public const quoteId = 'quoteId';
	public const additionalInfo = 'additionalInfo';
	public const isNew = 'isNew';
	public const isRepeatedApproach = 'isRepeatedApproach';
	public const leadId = 'leadId';
	public const isRecurring = 'isRecurring';
	public const isReturnCustomer = 'isReturnCustomer';
	public const locationId = 'locationId';
	public const previousStageId = 'previousStageId';
	public const type = 'type';
	public const previousStage = 'previousStage';
	public const lead = 'lead';
	public const quote = 'quote';

	use HasStagesTrait;
	use HasProductsTrait;
	use HasContactBindingsTrait;
	use HasCompanyTrait;
	use HasCategoriesTrait;
	use HasMyCompanyTrait;

	private ?string $typeId = null;
	private ?float $probability = null;
	private ?int $quoteId = null;
	private ?string $additionalInfo = null;
	private ?bool $isNew = null;
	private ?bool $isRepeatedApproach = null;
	private ?int $leadId = null;
	private ?bool $isRecurring = null;
	private ?bool $isReturnCustomer = null;
	private ?int $locationId = null;
	private ?string $previousStageId = null;
	private ?DealType $type = null;
	private ?Stage $previousStage = null;
	private ?Lead $lead = null;
	private ?Quote $quote = null;

	public function getEntityType(): EntityType
	{
		return EntityType::deal();
	}

	public function getTypeId(): ?string
	{
		return $this->typeId;
	}

	public function setTypeId(?string $typeId): static
	{
		$this->typeId = $typeId;
		$this->markChanged(self::typeId);

		return $this;
	}

	public function getPreviousStageId(): ?string
	{
		return $this->previousStageId;
	}

	public function getType(): ?DealType
	{
		return $this->type;
	}

	public function getPreviousStage(): ?Stage
	{
		return $this->previousStage;
	}

	public function getLead(): ?Lead
	{
		return $this->lead;
	}

	public function getQuote(): ?Quote
	{
		return $this->quote;
	}

	public function getProbability(): ?float
	{
		return $this->probability;
	}

	public function setProbability(?float $probability): static
	{
		$this->probability = $probability;
		$this->markChanged(self::probability);

		return $this;
	}

	public function getQuoteId(): ?int
	{
		return $this->quoteId;
	}

	public function setQuoteId(?int $quoteId): static
	{
		$this->quoteId = $quoteId;
		$this->markChanged(self::quoteId);

		return $this;
	}

	public function getAdditionalInfo(): ?string
	{
		return $this->additionalInfo;
	}

	public function setAdditionalInfo(?string $additionalInfo): static
	{
		$this->additionalInfo = $additionalInfo;
		$this->markChanged(self::additionalInfo);

		return $this;
	}

	// Read-only (computed by system)
	public function getIsNew(): ?bool
	{
		return $this->isNew;
	}

	public function getIsRepeatedApproach(): ?bool
	{
		return $this->isRepeatedApproach;
	}

	public function getIsReturnCustomer(): ?bool
	{
		return $this->isReturnCustomer;
	}

	public function getLeadId(): ?int
	{
		return $this->leadId;
	}

	public function setLeadId(?int $leadId): static
	{
		$this->leadId = $leadId;
		$this->markChanged(self::leadId);

		return $this;
	}

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

		match ($fieldName)
		{
			self::typeId => $this->typeId = $value,
			self::probability => $this->probability = $value,
			self::quoteId => $this->quoteId = $value,
			self::additionalInfo => $this->additionalInfo = $value,
			self::isNew => $this->isNew = $value,
			self::isRepeatedApproach => $this->isRepeatedApproach = $value,
			self::leadId => $this->leadId = $value,
			self::isRecurring => $this->isRecurring = $value,
			self::isReturnCustomer => $this->isReturnCustomer = $value,
			self::locationId => $this->locationId = $value,
			self::previousStageId => $this->previousStageId = $value,
			self::type => $this->type = $value,
			self::previousStage => $this->previousStage = $value,
			self::lead => $this->lead = $value,
			self::quote => $this->quote = $value,
			default => parent::internalSet($fieldName, $value),
		};

		return $this;
	}
}

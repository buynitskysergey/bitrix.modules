<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

use Bitrix\Crm\V2\Public\Entity\File;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasContactBindingsInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasCategoriesInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasMultifieldsInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasCategoriesTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasContactBindingsTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasMultifieldsTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasProductsTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasProductsInterface;
use Bitrix\Crm\V2\Public\EntityType;

final class Company extends Item implements HasMultifieldsInterface, HasContactBindingsInterface, HasCategoriesInterface, HasProductsInterface
{
	public const logo = 'logo';
	public const industry = 'industry';
	public const employees = 'employees';
	public const revenue = 'revenue';
	public const isMyCompany = 'isMyCompany';
	public const typeId = 'typeId';
	public const leadId = 'leadId';

	use HasMultifieldsTrait;
	use HasContactBindingsTrait;
	use HasProductsTrait;
	use HasCategoriesTrait;

	private ?File $logo = null;
	private ?string $industry = null;
	private ?string $employees = null;
	private ?float $revenue = null;
	private ?bool $isMyCompany = null;
	private ?string $typeId = null;
	private ?int $leadId = null;
	private ?Lead $lead = null;

	public function getEntityType(): EntityType
	{
		return EntityType::company();
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

	public function getLead(): ?Lead
	{
		return $this->lead;
	}

	public function getLogo(): ?File
	{
		return $this->logo;
	}

	public function setLogo(?File $logo): static
	{
		$this->logo = $logo;
		$this->markChanged(self::logo);

		return $this;
	}

	public function getIndustry(): ?string
	{
		return $this->industry;
	}

	public function setIndustry(?string $industry): static
	{
		$this->industry = $industry;
		$this->markChanged(self::industry);

		return $this;
	}

	public function getEmployees(): ?string
	{
		return $this->employees;
	}

	public function setEmployees(?string $employees): static
	{
		$this->employees = $employees;
		$this->markChanged(self::employees);

		return $this;
	}

	public function getRevenue(): ?float
	{
		return $this->revenue;
	}

	public function setRevenue(?float $revenue): static
	{
		$this->revenue = $revenue;
		$this->markChanged(self::revenue);

		return $this;
	}

	public function getIsMyCompany(): ?bool
	{
		return $this->isMyCompany;
	}

	public function setIsMyCompany(?bool $isMyCompany): static
	{
		$this->isMyCompany = $isMyCompany;
		$this->markChanged(self::isMyCompany);

		return $this;
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

	public function internalSet(string $fieldName, mixed $value): static
	{
		if ($this->internalSetContactBindingField($fieldName, $value))
		{
			return $this;
		}
		if ($this->internalSetMultifieldField($fieldName, $value))
		{
			return $this;
		}
		if ($this->internalSetProductField($fieldName, $value))
		{
			return $this;
		}
		if ($this->internalSetCategoryField($fieldName, $value))
		{
			return $this;
		}

		match ($fieldName)
		{
			self::logo => $this->logo = $value,
			self::industry => $this->industry = $value,
			self::employees => $this->employees = $value,
			self::revenue => $this->revenue = $value,
			self::isMyCompany => $this->isMyCompany = $value,
			self::typeId => $this->typeId = $value,
			self::leadId => $this->leadId = $value,
			'lead' => $this->lead = $value,
			default => parent::internalSet($fieldName, $value),
		};

		return $this;
	}
}

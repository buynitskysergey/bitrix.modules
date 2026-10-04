<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasCompanyInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasContactBindingsInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasProductsInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasStagesInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasCompanyTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasContactBindingsTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasMultifieldsTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasProductsTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasStagesTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasMultifieldsInterface;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Type\Date;

final class Lead extends Item implements
	HasMultifieldsInterface,
	HasStagesInterface,
	HasProductsInterface,
	HasContactBindingsInterface,
	HasCompanyInterface
{
	public const honorific = 'honorific';
	public const name = 'name';
	public const lastName = 'lastName';
	public const secondName = 'secondName';
	public const post = 'post';
	public const companyTitle = 'companyTitle';
	public const statusDescription = 'statusDescription';
	public const isReturnCustomer = 'isReturnCustomer';
	public const birthdate = 'birthdate';

	use HasStagesTrait;
	use HasProductsTrait;
	use HasContactBindingsTrait;
	use HasCompanyTrait;
	use HasMultifieldsTrait;

	private ?string $honorific = null;
	private ?string $name = null;
	private ?string $lastName = null;
	private ?string $secondName = null;
	private ?string $post = null;
	private ?string $companyTitle = null;
	private ?string $statusDescription = null;
	private ?bool $isReturnCustomer = null;
	private ?Date $birthdate = null;

	public function getEntityType(): EntityType
	{
		return EntityType::lead();
	}

	public function getCompanyTitle(): ?string
	{
		return $this->companyTitle;
	}

	public function getHonorific(): ?string
	{
		return $this->honorific;
	}

	public function setHonorific(?string $honorific): static
	{
		$this->honorific = $honorific;
		$this->markChanged(self::honorific);

		return $this;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function setName(?string $name): static
	{
		$this->name = $name;
		$this->markChanged(self::name);

		return $this;
	}

	public function getLastName(): ?string
	{
		return $this->lastName;
	}

	public function setLastName(?string $lastName): static
	{
		$this->lastName = $lastName;
		$this->markChanged(self::lastName);

		return $this;
	}

	public function getSecondName(): ?string
	{
		return $this->secondName;
	}

	public function setSecondName(?string $secondName): static
	{
		$this->secondName = $secondName;
		$this->markChanged(self::secondName);

		return $this;
	}

	public function getPost(): ?string
	{
		return $this->post;
	}

	public function setPost(?string $post): static
	{
		$this->post = $post;
		$this->markChanged(self::post);

		return $this;
	}

	public function setCompanyTitle(?string $companyTitle): static
	{
		$this->companyTitle = $companyTitle;
		$this->markChanged(self::companyTitle);

		return $this;
	}

	public function getBirthdate(): ?Date
	{
		return $this->birthdate;
	}

	public function setBirthdate(?Date $birthdate): static
	{
		$this->birthdate = $birthdate;
		$this->markChanged(self::birthdate);

		return $this;
	}

	public function getStatusDescription(): ?string
	{
		return $this->statusDescription;
	}

	public function setStatusDescription(?string $statusDescription): static
	{
		$this->statusDescription = $statusDescription;
		$this->markChanged(self::statusDescription);

		return $this;
	}

	// Read-only (computed by system)
	public function getIsReturnCustomer(): ?bool
	{
		return $this->isReturnCustomer;
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
		if ($this->internalSetMultifieldField($fieldName, $value))
		{
			return $this;
		}

		match ($fieldName)
		{
			self::birthdate => $this->birthdate = $value,
			self::honorific => $this->honorific = $value,
			self::name => $this->name = $value,
			self::lastName => $this->lastName = $value,
			self::secondName => $this->secondName = $value,
			self::post => $this->post = $value,
			self::companyTitle => $this->companyTitle = $value,
			self::statusDescription => $this->statusDescription = $value,
			self::isReturnCustomer => $this->isReturnCustomer = $value,
			default => parent::internalSet($fieldName, $value),
		};

		return $this;
	}
}

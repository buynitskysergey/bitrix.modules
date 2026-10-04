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
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasRecurringTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasStagesTrait;
use Bitrix\Crm\V2\Public\EntityType;

final class SmartProcess extends Item implements
	HasStagesInterface,
	HasProductsInterface,
	HasContactBindingsInterface,
	HasCompanyInterface,
	HasCategoriesInterface,
	HasBeginCloseDatesInterface
{
	use HasStagesTrait;
	use HasProductsTrait;
	use HasContactBindingsTrait;
	use HasCompanyTrait;
	use HasCategoriesTrait;
	use HasMyCompanyTrait;
	use HasRecurringTrait;

	public function __construct(
		private readonly EntityType $entityType,
	)
	{
	}

	public const isRecurring = 'isRecurring';

	public function getEntityType(): EntityType
	{
		return $this->entityType;
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
			default => parent::internalSet($fieldName, $value),
		};

		return $this;
	}
}

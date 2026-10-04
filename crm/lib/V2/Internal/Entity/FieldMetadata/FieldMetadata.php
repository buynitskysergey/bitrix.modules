<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\FieldMetadata;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\Validation\Rule\PropertyValidationAttributeInterface;

/**
 * @internal
 */
final readonly class FieldMetadata
{
	/**
	 * @param PropertyValidationAttributeInterface[] $validationRules
	 * @param string[]|null $requiredGroups
	 * @param string[]|null $editableGroups
	 */
	public function __construct(
		public string $name,
		public string $type,
		public ?string $elementType,
		public bool $multiple,
		public LocalizableMessage|string|null $title,
		public LocalizableMessage|string|null $description,
		public array $validationRules,
		public ?array $requiredGroups,
		public bool $filterable,
		public bool $sortable,
		public ?array $editableGroups,
		public bool $isUserField,
		public ?string $sourceUserFieldName,
	)
	{
	}

	public function withTitleAndDescription(
		LocalizableMessage|string|null $title,
		LocalizableMessage|string|null $description,
	): self
	{
		return new self(
			name: $this->name,
			type: $this->type,
			elementType: $this->elementType,
			multiple: $this->multiple,
			title: $title,
			description: $description,
			validationRules: $this->validationRules,
			requiredGroups: $this->requiredGroups,
			filterable: $this->filterable,
			sortable: $this->sortable,
			editableGroups: $this->editableGroups,
			isUserField: $this->isUserField,
			sourceUserFieldName: $this->sourceUserFieldName,
		);
	}
}

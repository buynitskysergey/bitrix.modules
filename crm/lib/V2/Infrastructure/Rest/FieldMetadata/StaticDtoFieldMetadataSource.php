<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\FieldMetadata;

use Bitrix\Crm\V2\Internal\Entity\FieldMetadata\FieldMetadata;
use Bitrix\Crm\V2\Internal\Repository\FieldMetadata\FieldMetadataSourceInterface;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;

/**
 * Metadata of the fields a DTO written by hand declares - as opposed to {@see GeneratedDtoFieldMetadataSource},
 * which asks a generator for the fields of an entity type. `Dto::create()` already builds the descriptions
 * of the fields by reflection over the properties and their attributes, so nothing has to be generated here.
 *
 * The class is given by the caller and is never read from the request: whoever answers with the metadata of
 * a DTO the client named answers about any DTO the client can name.
 *
 * User fields are not looked for. A contract served by a static DTO has none, and a relation to one is what
 * the source above resolves - there is nothing here to resolve it from.
 *
 * @internal
 */
final class StaticDtoFieldMetadataSource implements FieldMetadataSourceInterface
{
	/**
	 * @param class-string<Dto> $dtoFqcn
	 */
	public function __construct(
		private readonly string $dtoFqcn,
	)
	{
	}

	/**
	 * The type of the owner is part of the interface and is not used: the fields of a static DTO are the
	 * same for every entity type the DTO is published for.
	 *
	 * @return FieldMetadata[]
	 */
	public function getAll(EntityType $entityType): array
	{
		$fields = [];
		foreach ($this->getDtoFields() as $dtoField)
		{
			$fields[] = $this->mapField($dtoField);
		}

		return $fields;
	}

	public function getByName(EntityType $entityType, string $name): ?FieldMetadata
	{
		foreach ($this->getDtoFields() as $dtoField)
		{
			// exact match on the property name: a name differing in case is an unknown name
			if ($dtoField->getPropertyName() === $name)
			{
				return $this->mapField($dtoField);
			}
		}

		return null;
	}

	/**
	 * @return iterable<DtoField>
	 */
	private function getDtoFields(): iterable
	{
		return $this->dtoFqcn::create()->getFields();
	}

	private function mapField(DtoField $dtoField): FieldMetadata
	{
		return new FieldMetadata(
			name: $dtoField->getPropertyName(),
			type: $dtoField->getPropertyType(),
			elementType: $dtoField->getElementType(),
			multiple: $dtoField->isMultiple() || $dtoField->getPropertyType() === DtoCollection::class,
			title: $dtoField->getTitle(),
			description: $dtoField->getDescription(),
			validationRules: $dtoField->getValidationRules(),
			requiredGroups: $dtoField->getRequiredGroups(),
			filterable: $dtoField->isFilterable(),
			sortable: $dtoField->isSortable(),
			editableGroups: $dtoField->getEditableGroups(),
			isUserField: false,
			sourceUserFieldName: null,
		);
	}
}

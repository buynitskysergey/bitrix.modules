<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Exception\InvalidSelectException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Structure;

final class SelectStructure extends Structure
{
	/** @var string[] */
	private array $items = [];

	/** @var string[] */
	private array $userFields = [];

	/** @var string[] */
	private array $relationFields = [];

	/** @var array<string, string[]> */
	private array $nestedItems = [];

	/** @var array<string, true> */
	private array $fullRelationFields = [];

	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		if (!is_array($value) || !array_is_list($value))
		{
			throw new InvalidSelectException($value);
		}

		$structure = new self();
		$dto = self::getOrCreateDto($dtoClass);
		$scope = $request->getOptions()['scope'] ?? null;
		$availableFields = $scope?->fields ?? [];

		foreach ($value as $item)
		{
			if (!is_string($item))
			{
				throw new InvalidSelectException($item);
			}

			[$fieldName, $nestedFieldName] = array_pad(explode('.', $item, 2), 2, null);
			$field = $dto->getFields()[$fieldName] ?? null;
			if (
				$field === null
				|| ($availableFields !== [] && !in_array($fieldName, $availableFields, true))
			)
			{
				throw new UnknownDtoPropertyException($dto->getShortName(), $fieldName);
			}

			if ($nestedFieldName === null)
			{
				self::processTopLevelField($fieldName, $field, $structure);

				continue;
			}

			self::processNestedField($fieldName, $nestedFieldName, $field, $dto, $structure);
		}

		return $structure;
	}

	private static function processTopLevelField(string $fieldName, DtoField $field, self $structure): void
	{
		if (str_starts_with($fieldName, 'UF_'))
		{
			self::appendUnique($structure->userFields, $fieldName);

			return;
		}

		$childDtoClass = self::resolveChildDtoClass($field);
		if ($childDtoClass === null)
		{
			self::appendUnique($structure->items, $fieldName);

			return;
		}

		$childDto = self::getOrCreateDto($childDtoClass);
		self::appendRelationField($structure, $fieldName, $field);
		$structure->fullRelationFields[$fieldName] = true;

		foreach (self::getScalarPropertyFieldNames($childDto) as $childFieldName)
		{
			self::appendNestedItem($structure, $fieldName, $childFieldName);
		}
	}

	private static function processNestedField(
		string $fieldName,
		string $nestedFieldName,
		DtoField $field,
		Dto $dto,
		self $structure,
	): void
	{
		if (str_starts_with($fieldName, 'UF_') && !str_ends_with($fieldName, '_data'))
		{
			throw new UnknownDtoPropertyException($dto->getShortName(), $fieldName . '.' . $nestedFieldName);
		}

		$childDtoClass = self::resolveChildDtoClass($field);
		if ($childDtoClass === null)
		{
			throw new UnknownDtoPropertyException($dto->getShortName(), $fieldName . '.' . $nestedFieldName);
		}

		$childDto = self::getOrCreateDto($childDtoClass);
		if (
			str_contains($nestedFieldName, '.')
			|| !isset($childDto->getFields()[$nestedFieldName])
		)
		{
			throw new UnknownDtoPropertyException($childDto->getShortName(), $nestedFieldName);
		}

		self::appendRelationField($structure, $fieldName, $field);
		self::appendNestedItem($structure, $fieldName, $nestedFieldName);
	}

	private static function resolveChildDtoClass(DtoField $field): ?string
	{
		$propertyType = $field->getPropertyType();
		if (is_subclass_of($propertyType, Dto::class))
		{
			return $propertyType;
		}

		if ($propertyType !== DtoCollection::class)
		{
			return null;
		}

		$elementType = $field->getElementType();
		if ($elementType === null || !is_subclass_of($elementType, Dto::class))
		{
			return null;
		}

		return $elementType;
	}

	private static function getOrCreateDto(string $dtoClass): Dto
	{
		$dto = self::getDto($dtoClass);
		if ($dto === null)
		{
			$dto = $dtoClass::create();
			self::addDto($dto);
		}

		return $dto;
	}

	/** @return string[] */
	private static function getScalarPropertyFieldNames(Dto $dto): array
	{
		$result = [];
		foreach ($dto->getFields() as $field)
		{
			if (
				$field->getType() === DtoField::DTO_FIELD_TYPE_PROPERTY
				&& self::resolveChildDtoClass($field) === null
			)
			{
				$result[] = $field->getPropertyName();
			}
		}

		return $result;
	}

	private static function appendRelationField(self $structure, string $fieldName, DtoField $field): void
	{
		$relationFieldName = $field->getRelation()?->thisField ?? $fieldName;
		self::appendUnique($structure->relationFields, $relationFieldName);
	}

	private static function appendNestedItem(self $structure, string $fieldName, string $nestedFieldName): void
	{
		$structure->nestedItems[$fieldName] ??= [];
		self::appendUnique($structure->nestedItems[$fieldName], $nestedFieldName);
	}

	/** @param string[] $items */
	private static function appendUnique(array &$items, string $item): void
	{
		if (!in_array($item, $items, true))
		{
			$items[] = $item;
		}
	}

	/** @return string[] */
	public function getList(): array
	{
		return $this->items;
	}

	/** @return string[] */
	public function getUserFields(): array
	{
		return $this->userFields;
	}

	/** @return string[] */
	public function getRelationFields(): array
	{
		return $this->relationFields;
	}

	/** @return array<int|string, string|string[]> */
	public function getStructuredList(): array
	{
		$result = $this->items;
		foreach ($this->nestedItems as $fieldName => $nestedItems)
		{
			$result[$fieldName] = $nestedItems;
		}

		return $result;
	}

	public function isFullRelationSelected(string $fieldName): bool
	{
		return isset($this->fullRelationFields[$fieldName]);
	}
}

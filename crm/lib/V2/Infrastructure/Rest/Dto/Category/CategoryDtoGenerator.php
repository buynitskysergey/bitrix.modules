<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Category;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\DtoGeneratorInterface;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Schema\GeneratedDto;

/**
 * Builds the category DTO of one entity type out of {@see AbstractCategoryDto}.
 *
 * Which fields the entity type publishes and which of them it accepts on a write is not decided
 * here: both come from the category field model of that entity type
 * ({@see \Bitrix\Crm\Service\Factory::getCategoryFieldsInfo()}), the same source the storage boundary
 * refuses a write by. A field the model does not know is left out of the contract altogether -
 * a Deal category has no `isSystem` and no `code` - and a field the model keeps read-only stays in
 * the contract without being editable, so an attempt to write it is a validation error naming the
 * field rather than a silently dropped value.
 *
 * This holds for a smart process as much as for a static entity type, and it is why nothing here
 * knows about smart processes in particular: only {@see \Bitrix\Crm\Service\Factory\Deal} narrows
 * the model, so a smart process reads the whole of it - `isSystem` and `code` published for reading
 * and `isDefault` accepted on a write.
 */
class CategoryDtoGenerator implements DtoGeneratorInterface
{
	/**
	 * The field of the category field model that decides the fate of a DTO property. A property
	 * outside this map cannot be published at all.
	 */
	private const MODEL_FIELD_BY_PROPERTY = [
		'id' => 'ID',
		'name' => 'NAME',
		'sort' => 'SORT',
		'isDefault' => 'IS_DEFAULT',
		'isSystem' => 'IS_SYSTEM',
		'code' => 'CODE',
	];

	public function generate(int $entityTypeId = 0): GeneratedDto
	{
		static $dtoByEntityType = [];

		$entityType = EntityType::fromId($entityTypeId);
		if (!isset($dtoByEntityType[$entityType->getId()]))
		{
			$dtoByEntityType[$entityType->getId()] = new GeneratedDto(
				$this->getGeneratedClassName($entityType),
				$this->getGeneratedNamespace(),
				$this->getFields($entityType),
			);
		}

		return $dtoByEntityType[$entityType->getId()];
	}

	public function getGeneratedNamespace(): string
	{
		return __NAMESPACE__;
	}

	/**
	 * Own prefix rather than the one item DTOs use: a warm schema cache keeps generated classes by
	 * name, and two families sharing a name would hand each other's contract out. The code of the
	 * type carries its identifier for a smart process - `CategorySmartProcess1032Dto` - so the same
	 * cache cannot hand the contract of one smart process out for another.
	 */
	public function getGeneratedClassName(EntityType $entityType): string
	{
		return 'Category' . $entityType->getCode() . 'Dto';
	}

	/**
	 * @return DtoField[]
	 */
	protected function getFields(EntityType $entityType): array
	{
		$modelFields = $this->getCategoryFieldsInfo($entityType);

		$fields = [];
		/** @var DtoField $field */
		foreach ((new CategoryDto())->getFields() as $field)
		{
			$modelFieldName = self::MODEL_FIELD_BY_PROPERTY[$field->getPropertyName()] ?? null;
			$modelField = $modelFieldName === null ? null : ($modelFields[$modelFieldName] ?? null);
			if ($modelField === null)
			{
				continue;
			}

			$isEditable =
				$field->getEditableGroups() !== null
				&& !\CCrmFieldInfoAttr::isFieldReadOnly($modelField)
			;

			$fields[] = $this->toDynamicField($field, $isEditable);
		}

		return $fields;
	}

	/**
	 * @return array<string, array> The category field model of the entity type, empty for an entity
	 *         type that has no categories.
	 */
	protected function getCategoryFieldsInfo(EntityType $entityType): array
	{
		$factory = Container::getInstance()->getFactory($entityType->getId());

		return $factory?->isCategoriesSupported() ? $factory->getCategoryFieldsInfo() : [];
	}

	/**
	 * A generated DTO declares no properties of its own, so every field of it is a dynamic one. The
	 * validation rules are cloned: the source field belongs to the DTO the fields were read from,
	 * and the generated contract must not share mutable rules with it.
	 */
	private function toDynamicField(DtoField $field, bool $isEditable): DtoField
	{
		$fieldData = $field->toArray();
		$fieldData['type'] = DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD;
		$fieldData['editableGroups'] = $isEditable ? $field->getEditableGroups() : null;
		$fieldData['validationRules'] = array_map(
			static fn(object $rule): object => clone $rule,
			$fieldData['validationRules'] ?? [],
		);

		return DtoField::fromArray($fieldData);
	}
}

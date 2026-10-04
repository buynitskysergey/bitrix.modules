<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\FieldMetadata;

use Bitrix\Crm\V2\Internal\Entity\FieldMetadata\FieldMetadata;
use Bitrix\Crm\V2\Internal\Repository\Dictionary\EntityTypeTitleProvider;
use Bitrix\Crm\V2\Internal\Repository\FieldMetadata\FieldMetadataSourceInterface;
use Bitrix\Crm\V2\Internal\Repository\FieldMetadata\SystemFieldTitleProvider;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\Service\Container;

/**
 * @internal
 */
final class FieldMetadataService
{
	public function __construct(
		private readonly FieldMetadataSourceInterface $source,
		private readonly CustomFieldRegistry $customFieldRegistry,
		?SystemFieldTitleProvider $systemFieldTitleProvider = null,
		?EntityTypeTitleProvider $entityTypeTitleProvider = null,
	)
	{
		$this->systemFieldTitleProvider = $systemFieldTitleProvider ?? new SystemFieldTitleProvider();
		$this->entityTypeTitleProvider = $entityTypeTitleProvider ?? new EntityTypeTitleProvider();
	}

	private readonly SystemFieldTitleProvider $systemFieldTitleProvider;
	private readonly EntityTypeTitleProvider $entityTypeTitleProvider;

	/**
	 * @return FieldMetadata[]
	 */
	public function getAll(EntityType $entityType, int $userId, string $languageId): array
	{
		$fullFields = $this->source->getAll($entityType);
		[$userDefinitions, $visibleUserNames] = $this->getUserFieldContext($entityType, $userId, $languageId);
		$projectedFields = [];

		foreach ($fullFields as $field)
		{
			if (!$this->isFieldVisible($field, $visibleUserNames))
			{
				continue;
			}

			$projectedFields[] = $this->projectField($field, $userDefinitions, $entityType, $languageId);
		}

		return $projectedFields;
	}

	public function getByName(
		EntityType $entityType,
		string $name,
		int $userId,
		string $languageId,
	): ?FieldMetadata
	{
		$field = $this->source->getByName($entityType, $name);
		if ($field === null)
		{
			return null;
		}

		if (!$field->isUserField && $field->sourceUserFieldName === null)
		{
			return $this->projectField($field, [], $entityType, $languageId);
		}

		[$userDefinitions, $visibleUserNames] = $this->getUserFieldContext($entityType, $userId, $languageId);
		if (!$this->isFieldVisible($field, $visibleUserNames))
		{
			return null;
		}

		return $this->projectField($field, $userDefinitions, $entityType, $languageId);
	}

	/**
	 * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
	 */
	private function getUserFieldContext(EntityType $entityType, int $userId, string $languageId): array
	{
		$userDefinitions = $this->customFieldRegistry->getUserFields($entityType, $languageId, $userId);
		$forbiddenNames = array_fill_keys(
			$this->customFieldRegistry->getHiddenFieldNames($entityType, $userId),
			true,
		);

		return [$userDefinitions, array_diff_key($userDefinitions, $forbiddenNames)];
	}

	/**
	 * @param array<string, array<string, mixed>> $visibleUserNames
	 */
	private function isFieldVisible(FieldMetadata $field, array $visibleUserNames): bool
	{
		if ($field->isUserField && !array_key_exists($field->name, $visibleUserNames))
		{
			return false;
		}

		return $field->sourceUserFieldName === null
			|| array_key_exists($field->sourceUserFieldName, $visibleUserNames);
	}

	/**
	 * @param array<string, array<string, mixed>> $userDefinitions
	 */
	private function projectField(
		FieldMetadata $field,
		array $userDefinitions,
		EntityType $entityType,
		string $languageId,
	): FieldMetadata
	{
		$projectedField = $this->localizeSystemField($field, $entityType, $languageId);

		if ($field->isUserField)
		{
			$definition = $userDefinitions[$field->name];
			$projectedField = $field->withTitleAndDescription(
				$this->getUserFieldTitle($definition, $field->name),
				$this->emptyToNull($definition['HELP_MESSAGE'] ?? null),
			);
		}

		if ($field->sourceUserFieldName !== null)
		{
			if (str_ends_with($field->name, '_data'))
			{
				$definition = $userDefinitions[$field->sourceUserFieldName] ?? null;
				if (is_array($definition))
				{
					$projectedField = $projectedField->withTitleAndDescription(
						$this->getUserFieldTitle($definition, $field->sourceUserFieldName),
						$this->emptyToNull($definition['HELP_MESSAGE'] ?? null),
					);
				}
			}
			else
			{
				$projectedField = $projectedField->withTitleAndDescription(null, null);
			}
		}

		$relatedEntityTitle = $this->getRelatedEntityTitle($field->name, $languageId);
		if ($relatedEntityTitle !== null && ($field->title === null || $field->title === $field->name))
		{
			$projectedField = $projectedField->withTitleAndDescription($relatedEntityTitle, $field->description);
		}

		return $projectedField;
	}

	private function getRelatedEntityTitle(string $fieldName, string $languageId): ?string
	{
		if (!str_starts_with($fieldName, 'related'))
		{
			return null;
		}

		$entityCode = substr($fieldName, strlen('related'));
		if (str_ends_with($entityCode, 'Id'))
		{
			$entityCode = substr($entityCode, 0, -2);
		}

		$relatedEntityType = EntityType::fromCode($entityCode);
		if ($relatedEntityType === null)
		{
			return null;
		}

		if ($relatedEntityType->isSmartProcess())
		{
			$title = Container::getInstance()
				->getFactory($relatedEntityType->getId())
				?->getEntityDescription()
			;

			return $title !== null && $title !== '' ? $title : null;
		}

		$title = $this->entityTypeTitleProvider->getTitle($relatedEntityType, $languageId);

		return $title !== '' ? $title : null;
	}

	private function localizeSystemField(
		FieldMetadata $field,
		EntityType $entityType,
		string $languageId,
	): FieldMetadata
	{
		if ($field->isUserField || $field->sourceUserFieldName !== null || $field->title !== $field->name)
		{
			return $field;
		}

		$title = $this->systemFieldTitleProvider->getTitle($entityType, $field->name, $languageId);
		if ($title === null)
		{
			return $field;
		}

		return $field->withTitleAndDescription($title, $field->description);
	}

	/**
	 * @param array<string, mixed> $definition
	 */
	private function getUserFieldTitle(array $definition, string $fieldName): string
	{
		foreach (
			[
				$definition['EDIT_FORM_LABEL'] ?? null,
				$definition['LIST_COLUMN_LABEL'] ?? null,
				$definition['LIST_FILTER_LABEL'] ?? null,
				$fieldName,
			] as $value
		)
		{
			if ($value !== null && $value !== '')
			{
				return (string)$value;
			}
		}

		return $fieldName;
	}

	private function emptyToNull(mixed $value): ?string
	{
		return $value === null || $value === '' ? null : (string)$value;
	}
}

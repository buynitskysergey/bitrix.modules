<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Field;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field\CrmFieldMetadataDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\ElementsInArrayFrom;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Crm\V2\Internal\Entity\FieldMetadata\FieldMetadata;
use Bitrix\Main\Validation\Rule\PropertyValidationAttributeInterface;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;
use Bitrix\Rest\V3\Schema\TypeAliasRegistry;

final class CrmFieldMetadataMapper extends Mapper
{
	private const EXCLUDED_VALIDATION_RULES = [
		InArrayFrom::class,
		ElementsInArrayFrom::class,
	];

	private const PUBLIC_VALIDATION_RULE_PARAMETERS = [
		'NotEmpty' => ['allowZero', 'allowSpaces', 'errorMessage', 'groups'],
		'Length' => ['min', 'max', 'errorMessage', 'groups'],
		'Min' => ['min', 'errorMessage', 'groups'],
		'Max' => ['max', 'errorMessage', 'groups'],
		'PositiveNumber' => ['errorMessage', 'groups'],
		'ItemIdentifier' => [
			'entityTypeIds',
			'strict',
			'errorMessage',
			'showTypeValues',
			'showIdValues',
			'groups',
		],
		'ElementsItemIdentifier' => [
			'entityTypeIds',
			'strict',
			'errorMessage',
			'showTypeValues',
			'showIdValues',
			'groups',
		],
		'ElementsType' => ['errorMessage', 'groups'],
	];

	private const FIELDS = [
		'name',
		'type',
		'elementType',
		'multiple',
		'title',
		'description',
		'validationRules',
		'requiredGroups',
		'filterable',
		'sortable',
		'editable',
	];

	/**
	 * @var array<class-string<PropertyValidationAttributeInterface>, array{name: string, properties: \ReflectionProperty[]}>
	 */
	private array $validationRuleReflectionCache = [];

	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(CrmFieldMetadataDto::class);

		foreach ($items as $item)
		{
			$collection->add($this->mapField($item, $fields));
		}

		return $collection;
	}

	private function mapField(FieldMetadata $field, array $selectedFields): CrmFieldMetadataDto
	{
		$dto = new CrmFieldMetadataDto();
		$allFieldsSelected = $selectedFields === [];

		foreach (self::FIELDS as $fieldName)
		{
			if (!$allFieldsSelected && !in_array($fieldName, $selectedFields, true))
			{
				continue;
			}

			$dto->{$fieldName} = match ($fieldName)
			{
				'type' => TypeAliasRegistry::toPublicType($field->type),
				'elementType' => $field->elementType !== null
					? TypeAliasRegistry::toPublicType($field->elementType)
					: null,
				'editable' => $field->editableGroups !== null,
				'validationRules' => $this->mapValidationRules($field->validationRules),
				default => $field->{$fieldName},
			};
		}

		return $dto;
	}

	/**
	 * @param PropertyValidationAttributeInterface[] $validationRules
	 * @return array<int, array{name: string, parameters: array}>
	 */
	private function mapValidationRules(array $validationRules): array
	{
		$validationRules = array_filter(
			$validationRules,
			static fn (PropertyValidationAttributeInterface $rule): bool
				=> !in_array($rule::class, self::EXCLUDED_VALIDATION_RULES, true),
		);

		return array_values(array_map(
			function (PropertyValidationAttributeInterface $rule): array {
				$reflectionData = $this->getValidationRuleReflectionData($rule);

				return [
					'name' => $reflectionData['name'],
					'parameters' => $this->getValidationRuleParameters(
						$rule,
						$reflectionData['properties'],
						$reflectionData['name'],
					),
				];
			},
			$validationRules,
		));
	}

	/**
	 * @return array{name: string, properties: \ReflectionProperty[]}
	 */
	private function getValidationRuleReflectionData(PropertyValidationAttributeInterface $rule): array
	{
		$className = $rule::class;

		return $this->validationRuleReflectionCache[$className] ??= (static function () use ($rule): array {
			$reflection = new \ReflectionClass($rule);
			$properties = [];
			$classes = [];
			for ($class = $reflection; $class !== false; $class = $class->getParentClass())
			{
				$classes[] = $class;
			}
			$propertyClasses = [];
			foreach ($classes as $class)
			{
				foreach ($class->getProperties() as $property)
				{
					if (
						$property->getDeclaringClass()->getName() === $class->getName()
						&& !isset($propertyClasses[$property->getName()])
					)
					{
						$propertyClasses[$property->getName()] = $class->getName();
					}
				}
			}

			foreach (array_reverse($classes) as $class)
			{
				$classProperties = array_filter(
					$class->getProperties(),
					static fn(\ReflectionProperty $property): bool =>
						$property->getDeclaringClass()->getName() === $class->getName(),
				);
				$constructor = $class->getConstructor();
				$constructorParameterOrder = $constructor === null
					? []
					: array_flip(array_map(
						static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
						$constructor->getParameters(),
					));
				usort(
					$classProperties,
					static fn(\ReflectionProperty $left, \ReflectionProperty $right): int =>
						($constructorParameterOrder[$left->getName()] ?? PHP_INT_MAX)
						<=> ($constructorParameterOrder[$right->getName()] ?? PHP_INT_MAX),
				);

				foreach ($classProperties as $property)
				{
					if (
						$propertyClasses[$property->getName()] !== $class->getName()
						|| $property->isStatic()
						|| in_array($property->getName(), [
							'context',
							'validValues',
							'validEntitiesIdMapByTypeId',
						], true)
					)
					{
						continue;
					}

					$property->setAccessible(true);
					$properties[] = $property;
				}
			}

			return [
				'name' => $reflection->getShortName(),
				'properties' => $properties,
			];
		})();
	}

	/**
	 * @param \ReflectionProperty[] $properties
	 * @return array<string, mixed>
	 */
	private function getValidationRuleParameters(
		PropertyValidationAttributeInterface $rule,
		array $properties,
		string $ruleName,
	): array
	{
		$publicParameters = self::PUBLIC_VALIDATION_RULE_PARAMETERS[$ruleName] ?? [];
		$parameters = [];
		foreach ($properties as $property)
		{
			if (!in_array($property->getName(), $publicParameters, true))
			{
				continue;
			}

			if (!$property->isInitialized($rule))
			{
				continue;
			}

			$parameters[$property->getName()] = $this->normalizeValidationRuleParameter(
				$property->getValue($rule),
			);
		}

		return $parameters;
	}

	private function normalizeValidationRuleParameter(mixed $value): mixed
	{
		if (is_array($value))
		{
			return array_map(
				fn(mixed $item): mixed => $this->normalizeValidationRuleParameter($item),
				$value,
			);
		}

		return is_object($value) ? ['class' => $value::class] : $value;
	}
}

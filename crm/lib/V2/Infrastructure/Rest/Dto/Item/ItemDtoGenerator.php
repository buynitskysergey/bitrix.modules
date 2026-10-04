<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\DtoGeneratorInterface;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\BeginCloseDatesDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CategoriesDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CompanyDto as CommonCompanyDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ContactsDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ItemDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\MultifieldsDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ObserversDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ProductsDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\SourcesDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\StagesDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CrmTrackingDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\ContactTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ItemProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\MyCompanyProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Length;
use Bitrix\Crm\V2\Internal\Integration\Rest\File\RestIntegrationFactory;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Internal\Service\Item\FieldRegistry;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Dto\DtoFieldRelation;
use Bitrix\Rest\V3\Dto\DtoFieldsCollection;
use Bitrix\Rest\V3\Dto\PropertyHelper;
use Bitrix\Rest\V3\Realisation\Dto\FileDto;
use Bitrix\Rest\V3\Schema\GeneratedDto;

class ItemDtoGenerator implements DtoGeneratorInterface
{
	private ?RestIntegrationFactory $restIntegrationFactory = null;

	public function __construct(?RestIntegrationFactory $restIntegrationFactory = null)
	{
		$this->restIntegrationFactory = $restIntegrationFactory ?? new RestIntegrationFactory();
	}

	public function generate(int $entityTypeId = 0): GeneratedDto
	{
		/** @var array<string, GeneratedDto> $dtoByEntityType */
		static $dtoByEntityType = [];

		$entityType = EntityType::fromId($entityTypeId);
		$isFileContractAvailable = $this->getRestIntegrationFactory()->isFileContractAvailable();
		$cacheKey = static::class . ':' . ($isFileContractAvailable ? $entityTypeId : $entityTypeId . ':file-unavailable');
		if (!isset($dtoByEntityType[$cacheKey]))
		{
			$entityTypeSettings = $this->getEntityTypeSettings($entityType);

			$fields = $this->filterFieldsByRegisteredItemFields(
				$entityType,
				$this->getEntitySpecificFields($entityType),
			);
			if ($entityType->isSmartProcess())
			{
				$fields = array_merge($fields, $this->getSmartProcessFields($entityType));
			}
			$fields = array_merge($fields, $this->getCustomFields($entityType));
			if ($entityTypeSettings->hasStages())
			{
				$fields = array_merge($fields, $this->getStagesFields());
			}
			if ($entityTypeSettings->hasBeginCloseDates())
			{
				$fields = array_merge($fields, $this->getBeginCloseDatesFields());
			}
			if ($entityTypeSettings->hasCategories() && $entityType->getId() !== OwnerType::CONTACT)
			{
				$fields = array_merge($fields, $this->getCategoriesFields());
			}
			if ($entityTypeSettings->hasObservers())
			{
				$fields = array_merge($fields, $this->getObserversFields());
			}
			if ($entityTypeSettings->hasContactBindings())
			{
				$fields = array_merge($fields, $this->getContactsFields());
			}
			if ($entityType->getId() === OwnerType::CONTACT && $entityTypeSettings->hasCompanyBindings())
			{
				$fields = array_merge($fields, $this->getContactCompanyBindingsFields());
			}
			elseif ($entityTypeSettings->hasCompany())
			{
				$fields = array_merge($fields, $this->getCompanyFields());
			}
			if ($entityTypeSettings->hasMyCompany())
			{
				$fields = array_merge($fields, $this->getMyCompanyFields());
			}
			if ($entityTypeSettings->hasSource())
			{
				$fields = array_merge($fields, $this->getSourcesFields());
			}
			if ($entityTypeSettings->hasProducts())
			{
				$fields = array_merge($fields, $this->getProductsFields());
			}
			if ($entityTypeSettings->hasMultifields())
			{
				$fields = array_merge($fields, $this->getMultifieldsFields());
			}
			if ($entityTypeSettings->hasCrmTracking())
			{
				$fields = array_merge($fields, $this->getCrmTrackingFields());
			}
			$parentEntityTypesMap = $entityTypeSettings->getParentEntityTypesMap();
			if ($parentEntityTypesMap !== [])
			{
				$fields = array_merge($fields, $this->getRelatedFields($parentEntityTypesMap));
			}
			if (!$isFileContractAvailable)
			{
				$fields = array_values(array_filter(
					$fields,
					static fn(DtoField $field): bool =>
						$field->getPropertyType() !== FileDto::class
						&& $field->getElementType() !== FileDto::class,
				));
			}

			foreach ($fields as $field)
			{
				$this->addEntityTypeContextArg($field, $entityType);
				$this->addRelationToDtoTypeField($field);
			}

			$className = $this->getGeneratedClassName($entityType);
			$namespace = $this->getGeneratedNamespace();

			$dtoByEntityType[$cacheKey] = new GeneratedDto($className, $namespace, $fields);
		}

		return $dtoByEntityType[$cacheKey];
	}

	public function getGeneratedNamespace(): string
	{
		return __NAMESPACE__;
	}

	public function getGeneratedClassName(EntityType $entityType): string
	{
		return 'EntityType' . $entityType->getCode() . 'Dto';
	}

	protected function getEntityTypeSettings(EntityType $entityType): EntityTypeSettings
	{
		return EntityTypeSettings::of($entityType);
	}

	/**
	 * @return DtoField[]
	 */
	protected function getEntitySpecificFields(EntityType $entityType): array
	{
		if ($entityType->getId() === OwnerType::LEAD)
		{
			return $this->dtoFieldsCollectionToArray((new LeadDto($entityType))->getFields());
		}
		if ($entityType->getId() === OwnerType::DEAL)
		{
			return $this->dtoFieldsCollectionToArray((new DealDto($entityType))->getFields());
		}
		if ($entityType->getId() === OwnerType::CONTACT)
		{
			return array_merge(
				$this->dtoFieldsCollectionToArray((new ContactDto($entityType))->getFields()),
				[
					new DtoField(
						propertyName: 'type',
						propertyType: ContactTypeDto::class,
						type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
						nullable: true,
						relation: new DtoFieldRelation('typeId', 'id'),
					),
				],
			);
		}
		if ($entityType->getId() === OwnerType::COMPANY)
		{
			return $this->dtoFieldsCollectionToArray((new CompanyDto($entityType))->getFields());
		}
		if ($entityType->getId() === OwnerType::QUOTE)
		{
			return $this->dtoFieldsCollectionToArray((new QuoteDto($entityType))->getFields());
		}
		if ($entityType->getId() === OwnerType::SMART_INVOICE)
		{
			return $this->dtoFieldsCollectionToArray((new SmartInvoiceDto($entityType))->getFields());
		}
		if ($entityType->isSmartProcess())
		{
			return $this->getTitleFields();
		}

		return [
			new DtoField(
				propertyName: 'id',
				propertyType: 'int',
				type: DtoField::DTO_FIELD_TYPE_PROPERTY,
			),
		];
	}

	/**
	 * @return DtoField[]
	 */
	protected function getTitleFields(): array
	{
		return [
			new DtoField(
				propertyName: 'title',
				propertyType: 'string',
				type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
				validationRules: [
					new Length(max: 255),
				],
				editableGroups: [],
				filterable: true,
				sortable: true,
			),
		];
	}

	/** @return DtoField[] */
	protected function getSmartProcessFields(EntityType $entityType): array
	{
		$registeredItemFields = $this->getRegisteredItemFields($entityType);
		$commonFields = array_filter(
			$this->dtoFieldsCollectionToArray((new class extends AbstractItemDto {})->getFields()),
			static fn(DtoField $field): bool =>
				isset($registeredItemFields[$field->getPropertyName()])
				|| (
					$field->getRelation() !== null
					&& (
						$field->getRelation()->thisField === $field->getPropertyName()
						|| isset($registeredItemFields[$field->getRelation()->thisField])
					)
				),
		);

		return array_merge(
			[
				new DtoField(
					propertyName: 'xmlId',
					propertyType: 'string',
					type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
					validationRules: [new Length(max: 255)],
					requiredGroups: [],
					editableGroups: [],
					filterable: true,
					sortable: true,
					nullable: true,
				),
			],
			$commonFields,
		);
	}

	/** @return array<string, mixed> */
	protected function getRegisteredItemFields(EntityType $entityType): array
	{
		return FieldRegistry::getInstance($entityType)->getFields();
	}

	/**
	 * @param DtoField[] $fields
	 * @return DtoField[]
	 */
	private function filterFieldsByRegisteredItemFields(EntityType $entityType, array $fields): array
	{
		$registeredItemFields = $this->getRegisteredItemFields($entityType);
		$commonFieldNames = [];
		foreach ((new class extends AbstractItemDto {})->getFields() as $field)
		{
			$commonFieldNames[$field->getPropertyName()] = true;
		}

		return array_filter(
			$fields,
			static function (DtoField $field) use ($commonFieldNames, $registeredItemFields): bool
			{
				if (!isset($commonFieldNames[$field->getPropertyName()]))
				{
					return true;
				}

				if (isset($registeredItemFields[$field->getPropertyName()]))
				{
					return true;
				}

				$relation = $field->getRelation();
				if ($relation === null)
				{
					return false;
				}

				return $relation->thisField === $field->getPropertyName()
					|| isset($registeredItemFields[$relation->thisField]);
			},
		);
	}

	/**
	 * @return DtoField[]
	 */
	protected function getCustomFields(EntityType $entityType): array
	{
		$customFieldGenerator = new CustomFieldDtoGenerator(
			CustomFieldRegistry::getInstance(),
			$this->getRestIntegrationFactory(),
		);

		return $customFieldGenerator->generateCustomFields($entityType);
	}

	private function getRestIntegrationFactory(): RestIntegrationFactory
	{
		return $this->restIntegrationFactory ??= new RestIntegrationFactory();
	}

	/**
	 * @return DtoField[]
	 */
	protected function getStagesFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new StagesDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getBeginCloseDatesFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new BeginCloseDatesDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getCategoriesFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new CategoriesDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getObserversFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new ObserversDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getContactsFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new ContactsDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getCompanyFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new CommonCompanyDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getContactCompanyBindingsFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new ContactCompanyBindingsDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getMyCompanyFields(): array
	{
		return [
			new DtoField(
				propertyName: 'myCompanyId',
				propertyType: 'int',
				type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
				validationRules: [new InArrayFrom(MyCompanyProvider::class)],
				editableGroups: [],
				nullable: true,
				filterable: true,
				sortable: true,
			),
			new DtoField(
				propertyName: 'myCompany',
				propertyType: ItemDto::class,
				type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
				nullable: true,
				relation: new DtoFieldRelation(
					thisField: 'myCompanyId',
					refField: 'id',
				),
			),
		];
	}

	/**
	 * @return DtoField[]
	 */
	protected function getSourcesFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new SourcesDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getProductsFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new ProductsDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getMultifieldsFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new MultifieldsDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getCrmTrackingFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new CrmTrackingDto())->getFields());
	}

	/**
	 * @param EntityType[] $parentEntityTypes
	 * @return DtoField[]
	 */
	protected function getRelatedFields(array $parentEntityTypes): array
	{
		$result = [];
		foreach ($parentEntityTypes as $parentEntityType)
		{
			$result[] = new DtoField(
				propertyName: 'related' . $parentEntityType->getCode() . 'Id',
				propertyType: 'int',
				type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
				validationRules: [
					new InArrayFrom(ItemProvider::class, ['entityTypeId' => $parentEntityType->getId()]),
				],
				editableGroups: [],
				nullable: true,
			);
			$result[] = new DtoField(
				propertyName: 'related' . $parentEntityType->getCode(),
				propertyType: ItemDto::class,
				type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
				nullable: true,
				relation: new DtoFieldRelation(
					thisField: 'related' . $parentEntityType->getCode() . 'Id',
					refField: 'id',
				),
			);
		}

		return $result;
	}

	protected function dtoFieldsCollectionToArray(DtoFieldsCollection $fields): array
	{
		$result = [];
		/** @var DtoField $field */
		foreach ($fields as $field)
		{
			$fieldData = $field->toArray();
			$fieldData['type'] = DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD;
			$fieldData['validationRules'] = array_map(
				static fn(object $rule): object => clone $rule,
				$fieldData['validationRules'] ?? [],
			);
			$result[] = DtoField::fromArray($fieldData);
		}

		return $result;
	}

	protected function addEntityTypeContextArg(DtoField $field, EntityType $entityType): void
	{
		foreach ($field->getValidationRules() as $validationRule)
		{
			if ($validationRule instanceof InArrayFrom && !$validationRule->hasContextArg('entityTypeId'))
			{
				$validationRule->addContextArg('entityTypeId', $entityType->getId());
			}
		}
	}

	protected function addRelationToDtoTypeField(DtoField $field): void
	{
		$propertyType = $field->getElementType() ?? $field->getPropertyType();
		if (
			$propertyType === FileDto::class
			|| !is_subclass_of($propertyType, Dto::class)
			|| $field->getRelation() !== null
		)
		{
			return;
		}

		$refProperties = PropertyHelper::getProperties($propertyType);
		$firstProperty = reset($refProperties);
		if (!$firstProperty)
		{
			return;
		}

		$selfRelation = new DtoFieldRelation(
			thisField: $field->getPropertyName(),
			refField: $firstProperty->getName(),
		);
		$field->setRelation($selfRelation);
	}
}

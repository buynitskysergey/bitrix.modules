<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\DataSource;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\DataView\Dto\AggregateFunction;
use Bitrix\Bizproc\Public\DataView\Dto\AggregateFunctionCall;
use Bitrix\Bizproc\Public\DataView\Dto\AggregateQuery;
use Bitrix\Bizproc\Public\DataView\Dto\ExtractQuery;
use Bitrix\Bizproc\Public\DataView\Dto\SourceDescriptor;
use Bitrix\Bizproc\Public\DataView\Dto\SourceField;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRelation;
use Bitrix\Bizproc\Public\DataView\Dto\SourceSchema;
use Bitrix\Bizproc\Public\DataView\Exception\RowLimitExceededException;
use Bitrix\Bizproc\Public\DataView\Exception\SourceUnavailableException;
use Bitrix\Bizproc\Public\DataView\Interface\AggregationCapableProvider;
use Bitrix\Bizproc\Public\DataView\Interface\DataSourceProvider as DataSourceProviderInterface;
use Bitrix\Bizproc\Public\DataView\Interface\PresentableProvider;
use Bitrix\Crm\Category\PermissionEntityTypeHelper;
use Bitrix\Crm\Field;
use Bitrix\Crm\Item;
use Bitrix\Crm\Model\ItemCategoryTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Display;
use Bitrix\Crm\Service\Display\Field as DisplayField;
use Bitrix\Crm\Service\Display\Options as DisplayOptions;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\ParentFieldManager;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\UserField\Visibility\VisibilityManager;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

final class DataSourceProvider implements DataSourceProviderInterface, AggregationCapableProvider, PresentableProvider
{
	public const MODULE_ID = 'crm';

	/**
	 * Keyset page size for {@see self::extract()}: bounds the number of fully hydrated CRM items held
	 * at once, so aggregation of wide entities cannot exhaust memory before the engine input limit.
	 */
	private const EXTRACT_PAGE_SIZE = 500;

	private const FIELD_TYPE_MAP = [
		Field::TYPE_INTEGER => FieldType::INT,
		Field::TYPE_DOUBLE => FieldType::DOUBLE,
		Field::TYPE_BOOLEAN => FieldType::BOOL,
		Field::TYPE_DATE => FieldType::DATE,
		Field::TYPE_DATETIME => FieldType::DATETIME,
		Field::TYPE_STRING => FieldType::STRING,
		Field::TYPE_TEXT => FieldType::STRING,
		'money' => FieldType::DOUBLE,
	];

	/**
	 * @var array<string, SourceSchema>
	 */
	private array $schemaCache = [];

	/**
	 * Memoized table name → set of its leading index columns (column => true). Reading the whole
	 * index map once per table keeps schema build from issuing one SHOW INDEX per reference field.
	 *
	 * @var array<string, array<string, true>>
	 */
	private array $leadingIndexColumnsByTable = [];

	private readonly Container $container;

	public function __construct(?Container $container = null)
	{
		$this->container = $container ?? Container::getInstance();
	}

	public function getModuleId(): string
	{
		return self::MODULE_ID;
	}

	/**
	 * @return SourceDescriptor[]
	 */
	public function getAvailableSources(int $actorId): array
	{
		if ($actorId <= 0)
		{
			return [];
		}

		$userPermissions = $this->container->getUserPermissions($actorId);

		$factories = [];
		foreach ($this->container->getTypesMap()->getFactories() as $factory)
		{
			if (\CCrmOwnerType::isUseFactoryBasedApproach($factory->getEntityTypeId()))
			{
				$factories[] = $factory;
			}
		}

		$categoryIdsByType = $this->preloadDynamicCategoryIds($factories);

		$descriptors = [];
		foreach ($factories as $factory)
		{
			$entityTypeId = $factory->getEntityTypeId();
			if (!$this->canReadSomeItemsOfType($userPermissions, $entityTypeId, $categoryIdsByType))
			{
				continue;
			}

			$entity = \CCrmOwnerType::ResolveName($entityTypeId);
			if ($entity === '')
			{
				continue;
			}

			$descriptors[] = new SourceDescriptor(
				module: self::MODULE_ID,
				entity: $entity,
				title: $factory->getEntityDescription(),
				requiresParams: false,
				bounded: false,
				params: [],
			);
		}

		return $descriptors;
	}

	/**
	 * Reads whether the actor may read at least one item of the type. For pure dynamic types the
	 * read gate resolves to "readable in at least one category" (Type::canDoOperation), so the
	 * category ids are preloaded once for the whole catalog and checked against a prepared map —
	 * this replaces per-factory Factory::getCategories() lookups that made the catalog N+1 on
	 * portals with many smart processes. Every other type keeps the untouched canReadItems() path.
	 *
	 * @param array<int, int[]> $categoryIdsByType
	 */
	private function canReadSomeItemsOfType(
		UserPermissions $userPermissions,
		int $entityTypeId,
		array $categoryIdsByType,
	): bool
	{
		if (\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			foreach ($categoryIdsByType[$entityTypeId] ?? [] as $categoryId)
			{
				if ($userPermissions->entityType()->canReadItemsInCategory($entityTypeId, $categoryId))
				{
					return true;
				}
			}

			return false;
		}

		return $userPermissions->entityType()->canReadItems($entityTypeId);
	}

	/**
	 * One query for the category ids of every dynamic type in the catalog, grouped by type.
	 *
	 * @param Factory[] $factories
	 * @return array<int, int[]>
	 */
	private function preloadDynamicCategoryIds(array $factories): array
	{
		$dynamicTypeIds = [];
		foreach ($factories as $factory)
		{
			$entityTypeId = $factory->getEntityTypeId();
			if (\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
			{
				$dynamicTypeIds[] = $entityTypeId;
			}
		}

		if ($dynamicTypeIds === [])
		{
			return [];
		}

		$map = [];
		foreach (array_chunk($dynamicTypeIds, 500) as $chunk)
		{
			$rows = ItemCategoryTable::query()
				->setSelect(['ID', 'ENTITY_TYPE_ID'])
				->whereIn('ENTITY_TYPE_ID', $chunk)
				->setCacheTtl(3600)
				->exec()
			;
			while ($row = $rows->fetch())
			{
				$map[(int)$row['ENTITY_TYPE_ID']][] = (int)$row['ID'];
			}
		}

		return $map;
	}

	/**
	 * @throws SourceUnavailableException
	 */
	public function getSourceSchema(SourceRef $source): SourceSchema
	{
		if (isset($this->schemaCache[$source->entity]))
		{
			return $this->schemaCache[$source->entity];
		}

		$factory = $this->resolveFactory($source);

		$fields = [];
		foreach ($factory->getFieldsCollection() as $field)
		{
			if ($field->isHidden())
			{
				continue;
			}

			$multiple = $field->isMultiple();
			$fields[] = new SourceField(
				code: $field->getName(),
				title: $field->getTitle(),
				type: $this->resolveFieldType($field),
				multiple: $multiple,
				joinable: !$multiple,
				indexed: $this->isIndexedJoinKey($field, $factory),
				presentable: $this->isPresentableField($field),
			);
		}

		return $this->schemaCache[$source->entity] = new SourceSchema($fields);
	}

	/**
	 * @return iterable<array<string, scalar|null|array>>
	 * @throws SourceUnavailableException
	 */
	public function extract(SourceRef $source, ExtractQuery $query, int $actorId): iterable
	{
		$factory = $this->resolveFactory($source);

		$this->assertRequestedFieldsExist($source, $query);

		if ($query->limit <= 0)
		{
			return;
		}

		$filter = [];

		if ($query->hasDateWindow())
		{
			$filter = $this->dateWindowFilter($factory, $query, $source->entity);
		}

		if ($query->hasKeyIn() && $query->getKeyValues() !== [])
		{
			$filter['@' . $query->getKeyField()] = $query->getKeyValues();
		}

		$select = $this->accessibleSelect($factory, $query, $actorId);
		if ($select === [])
		{
			// Every requested field is hidden from the actor by CRM field visibility: the row set
			// must stay empty rather than fall back to the whole schema.
			return;
		}

		$remaining = $query->limit;
		$lastId = 0;
		while ($remaining > 0)
		{
			$pageSize = min(self::EXTRACT_PAGE_SIZE, $remaining);
			$items = $factory->getItemsFilteredByPermissions(
				[
					'filter' => ['>' . Item::FIELD_NAME_ID => $lastId] + $filter,
					'order' => [Item::FIELD_NAME_ID => 'ASC'],
					'limit' => $pageSize,
					'select' => $select,
				],
				$actorId,
				UserPermissions::OPERATION_READ,
			);

			$fetched = 0;
			foreach ($items as $item)
			{
				$lastId = $item->getId();
				$fetched++;
				yield $this->projectRow($factory, $item, $select);
			}

			if ($fetched < $pageSize)
			{
				break;
			}

			$remaining -= $fetched;
		}
	}

	/**
	 * @param iterable<int|string, array<string, mixed>> $rows
	 * @return iterable<int|string, array<string, string>>
	 */
	public function present(SourceRef $source, iterable $rows, int $actorId = 0): iterable
	{
		$rows = is_array($rows) ? $rows : iterator_to_array($rows, true);
		if ($rows === [])
		{
			return [];
		}

		$factory = $this->resolveFactory($source);

		$fieldsByCode = [];
		foreach ($factory->getFieldsCollection() as $field)
		{
			$fieldsByCode[$field->getName()] = $field;
		}

		$presentableFields = [];
		foreach ($rows as $row)
		{
			foreach ((array)$row as $code => $value)
			{
				if (isset($presentableFields[$code]))
				{
					continue;
				}

				$field = $fieldsByCode[$code] ?? null;
				if ($field !== null && $this->isPresentableField($field))
				{
					$presentableFields[$code] = $field;
				}
			}
		}

		if ($presentableFields === [])
		{
			return [];
		}

		$displayFields = [];
		$categoryCodes = [];
		foreach ($presentableFields as $code => $field)
		{
			// Display knows no field class for a category and would print the raw id through OtherField.
			if ($field->getType() === Field::TYPE_CRM_CATEGORY)
			{
				$categoryCodes[] = $code;

				continue;
			}

			$displayField = $field->isUserField()
				? DisplayField::createFromUserField($code, $field->getUserField())
				: DisplayField::createFromBaseField($code, $field->toArray());
			$displayField->setContext(DisplayField::EXPORT_CONTEXT);
			$displayFields[$code] = $displayField;
		}

		$rowKeys = array_keys($rows);
		$displayValues = $displayFields === [] ? [] : $this->displayValues($factory, $displayFields, $rows, $rowKeys);
		// A row is presented under the permissions it was extracted with, not the reader's of the moment:
		// a recompute runs for the view's actor while the current user may be anyone, even nobody.
		$userPermissions = $this->container->getUserPermissions($actorId > 0 ? $actorId : null);

		$result = [];
		foreach ($rowKeys as $position => $rowKey)
		{
			$itemId = $position + 1;
			$out = [];
			foreach (array_keys($displayFields) as $code)
			{
				// Display escapes every label for an HTML grid, while a presented value is plain text:
				// its consumer escapes once on its own, so keeping Display's escaping doubles it.
				$out[$code] = htmlspecialcharsback((string)($displayValues[$itemId][$code] ?? ''));
			}

			foreach ($categoryCodes as $code)
			{
				$out[$code] = $this->presentCategory(
					$factory,
					$userPermissions,
					((array)$rows[$rowKey])[$code] ?? null,
				);
			}

			$result[$rowKey] = $out;
		}

		return $result;
	}

	/**
	 * @param array<string, DisplayField> $displayFields
	 * @param array<int|string, array<string, mixed>> $rows
	 * @param array<int, int|string> $rowKeys
	 * @return array<int, array<string, string>>
	 */
	private function displayValues(Factory $factory, array $displayFields, array $rows, array $rowKeys): array
	{
		$display = new Display($factory->getEntityTypeId(), $displayFields, new DisplayOptions());

		$items = [];
		foreach ($rowKeys as $position => $rowKey)
		{
			$items[$position + 1] = (array)$rows[$rowKey];
		}
		$display->setItems($items);

		return $display->getAllValues();
	}

	/**
	 * Name of the category the value points at. A smart process has categories of its own, so the name
	 * is asked of the entity factory and not of the deal category list. Presentation follows the same
	 * permissions as the extraction the row came from: a category the actor may not read stays unnamed.
	 */
	private function presentCategory(Factory $factory, UserPermissions $userPermissions, mixed $value): string
	{
		if (!is_numeric($value))
		{
			return '';
		}

		$categoryId = (int)$value;

		return $userPermissions->entityType()->canReadItemsInCategory($factory->getEntityTypeId(), $categoryId)
			? (string)$factory->getCategory($categoryId)?->getName()
			: '';
	}

	private function isPresentableField(Field $field): bool
	{
		if ($field->isUserField())
		{
			$userType = (string)($field->getUserField()['USER_TYPE_ID'] ?? '');

			return in_array($userType, ['enumeration', 'employee', 'crm', 'money'], true);
		}

		if ($field->getValueType() === Field::VALUE_TYPE_MONEY)
		{
			return true;
		}

		return in_array($field->getType(), [
			Field::TYPE_CRM_STATUS,
			Field::TYPE_CRM_CATEGORY,
			Field::TYPE_CRM_CURRENCY,
			Field::TYPE_CRM_COMPANY,
			Field::TYPE_CRM_CONTACT,
			Field::TYPE_CRM_LEAD,
			Field::TYPE_CRM_DEAL,
			Field::TYPE_CRM_QUOTE,
			Field::TYPE_CRM_ENTITY,
			Field::TYPE_USER,
			Field::TYPE_BOOLEAN,
		], true);
	}

	/**
	 * NORMATIVE (ALG-02, ADR D4). Push-down aggregate: the CRM factory computes GROUP BY and the
	 * aggregate functions on its own side, so only result rows (groups) — not raw source rows — reach
	 * PHP. It is the equivalent of the in-PHP fallback folding {@see self::extract()}: the same
	 * permissions ($actorId), the same field visibility ({@see VisibilityManager}) and the same period
	 * window are applied, so both paths yield identical groups and values.
	 *
	 * A group whose grouping field is hidden from the actor collapses to no rows (extract() would drop
	 * the field and the group key could not be built); a hidden function column yields the empty-set
	 * value (COUNT => 0, the rest => null), matching the in-PHP fold over a dropped column.
	 *
	 * Overflow is an error, never a silent truncation: the query reads limit + 1 groups and raises
	 * {@see RowLimitExceededException} when more than the limit come back.
	 *
	 * @return array<int, array<string, scalar|null>>
	 * @throws SourceUnavailableException
	 * @throws RowLimitExceededException
	 */
	public function aggregate(SourceRef $source, AggregateQuery $query, int $actorId): iterable
	{
		// The facade denies an unauthenticated actor; mirror it so the push-down never widens access.
		if ($actorId <= 0)
		{
			return [];
		}

		$factory = $this->resolveFactory($source);
		$visibleColumns = $this->accessibleAggregateColumns($factory, $query, $actorId);

		foreach ($query->groupBy as $code)
		{
			if (!isset($visibleColumns[$code]))
			{
				return [];
			}
		}

		$dataClass = $factory->getDataClass();
		$ormQuery = $dataClass::query();
		$ormQuery = $this->applyItemPermissions($ormQuery, $factory, $actorId);

		foreach ($query->groupBy as $code)
		{
			$ormField = $factory->getEntityFieldNameByMap($code);
			$ormQuery->addGroup($ormField);
			$ormQuery->addSelect($ormField, $code);
		}

		$hiddenFunctions = [];
		foreach ($query->functions as $function)
		{
			if (!isset($visibleColumns[$function->column]))
			{
				$hiddenFunctions[$function->code] = $function;

				continue;
			}

			$ormField = $factory->getEntityFieldNameByMap($function->column);
			$ormQuery->addSelect($this->aggregateExpression($function->fn, $ormField), $function->code);
		}

		if ($query->hasDateWindow())
		{
			$this->applyAggregateDateWindow($ormQuery, $factory, $query, $source->entity);
		}

		$ormQuery->setLimit($query->limit + 1);

		$rows = $ormQuery->exec()->fetchAll();
		if (count($rows) > $query->limit)
		{
			throw RowLimitExceededException::limit($query->limit);
		}

		return $this->projectAggregateRows($rows, $query, $hiddenFunctions);
	}

	/**
	 * Used columns (group fields plus function arguments) that survive CRM user-field visibility for
	 * the actor, as a set for O(1) lookup. Mirrors {@see self::accessibleSelect()} so the push-down
	 * hides exactly the fields the in-PHP path would drop from the extract select.
	 *
	 * @return array<string, true>
	 */
	private function accessibleAggregateColumns(Factory $factory, AggregateQuery $query, int $actorId): array
	{
		$used = $query->getUsedColumns();

		if (!VisibilityManager::isEnabled())
		{
			return array_fill_keys($used, true);
		}

		$visible = VisibilityManager::filterNotAccessibleFields(
			$factory->getEntityTypeId(),
			$used,
			VisibilityManager::getUserAccessCodes($actorId),
		);

		return array_fill_keys(array_values($visible), true);
	}

	/**
	 * Applies the actor's READ permissions to the aggregate query, reproducing the entity-type and
	 * category restrictions {@see Factory::collectEntityTypesForPermissions()} builds for
	 * {@see Factory::getItemsFilteredByPermissions()} — the exact permission contract of extract().
	 */
	private function applyItemPermissions(Query $ormQuery, Factory $factory, int $actorId): Query
	{
		$userPermissions = $this->container->getUserPermissions($actorId);
		$helper = new PermissionEntityTypeHelper($factory->getEntityTypeId());

		if (!$factory->isCategoriesSupported())
		{
			return $userPermissions->itemsList()->applyAvailableItemsQueryParameters(
				$ormQuery,
				[$helper->getPermissionEntityTypeForCategory(0)],
				UserPermissions::OPERATION_READ,
			);
		}

		$entityTypes = [];
		$availableCategoryIds = [];
		$restrictByCategory = false;
		foreach ($factory->getCategories() as $category)
		{
			$categoryId = (int)$category->getId();
			$entityTypes[] = $helper->getPermissionEntityTypeForCategory($categoryId);

			if ($userPermissions->entityType()->canReadItemsInCategory($factory->getEntityTypeId(), $categoryId))
			{
				$availableCategoryIds[] = $categoryId;
			}
			else
			{
				$restrictByCategory = true;
			}
		}

		if ($restrictByCategory && $availableCategoryIds !== [])
		{
			$ormQuery->whereIn($factory->getEntityFieldNameByMap(Item::FIELD_NAME_CATEGORY_ID), $availableCategoryIds);
		}

		return $userPermissions->itemsList()->applyAvailableItemsQueryParameters(
			$ormQuery,
			$entityTypes,
			UserPermissions::OPERATION_READ,
		);
	}

	/**
	 * Half-open [from, to) window on the record creation date, mirroring {@see self::dateWindowFilter()}:
	 * the factory maps the common CREATED_TIME code onto the entity ORM column (DATE_CREATE for deals,
	 * CREATED_TIME for smart processes). An entity without a creation date cannot honour the freshness
	 * window, so — as in extract() — it fails explicitly instead of silently scanning full history.
	 *
	 * @throws SourceUnavailableException
	 */
	private function applyAggregateDateWindow(Query $ormQuery, Factory $factory, AggregateQuery $query, string $entity): void
	{
		if (!$factory->isFieldExists(Item::FIELD_NAME_CREATED_TIME))
		{
			throw SourceUnavailableException::sourceDateWindowUnsupported($entity);
		}

		$createdField = $factory->getEntityFieldNameByMap(Item::FIELD_NAME_CREATED_TIME);
		$ormQuery->where($createdField, '>=', $query->getDateFrom());
		$ormQuery->where($createdField, '<', $query->getDateTo());
	}

	private function aggregateExpression(AggregateFunction $function, string $ormField): ExpressionField
	{
		$expr = Query::expr();

		return match ($function)
		{
			AggregateFunction::Sum => $expr->sum($ormField),
			AggregateFunction::Count => $expr->count($ormField),
			AggregateFunction::Avg => $expr->avg($ormField),
			AggregateFunction::Min => $expr->min($ormField),
			AggregateFunction::Max => $expr->max($ormField),
		};
	}

	/**
	 * Shapes fetched ORM rows into the {@see AggregationCapableProvider::aggregate()} contract: group
	 * values by their codes plus one value per function code. Hidden functions receive the empty-set
	 * value; visible ones are typed to match the in-PHP fold.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<string, AggregateFunctionCall> $hiddenFunctions
	 * @return array<int, array<string, scalar|null>>
	 */
	private function projectAggregateRows(array $rows, AggregateQuery $query, array $hiddenFunctions): array
	{
		$projected = [];
		foreach ($rows as $row)
		{
			$values = [];
			foreach ($query->groupBy as $code)
			{
				$values[$code] = $this->normalizeValue($row[$code] ?? null);
			}

			foreach ($query->functions as $function)
			{
				$values[$function->code] = isset($hiddenFunctions[$function->code])
					? $this->emptyAggregateValue($function->fn)
					: $this->normalizeAggregateValue(
						$this->normalizeValue($row[$function->code] ?? null),
						$function->fn,
					)
				;
			}

			$projected[] = $values;
		}

		return $projected;
	}

	/**
	 * Value of a function over a column the actor cannot see — identical to the in-PHP fold over a
	 * dropped column: COUNT sees no values (0), the rest have no defined value (null).
	 */
	private function emptyAggregateValue(AggregateFunction $function): int|null
	{
		return $function === AggregateFunction::Count ? 0 : null;
	}

	/**
	 * Types a fetched aggregate value to match the in-PHP fold: COUNT is an int, an empty aggregate
	 * (SQL NULL) stays null, SUM/AVG are float, MIN/MAX keep the source value (numeric ones cast to
	 * float, so money folds compare equal across both paths).
	 */
	private function normalizeAggregateValue(mixed $value, AggregateFunction $function): int|float|string|null
	{
		if ($function === AggregateFunction::Count)
		{
			return (int)$value;
		}

		if ($value === null)
		{
			return null;
		}

		return match ($function)
		{
			AggregateFunction::Sum, AggregateFunction::Avg => (float)$value,
			AggregateFunction::Min, AggregateFunction::Max => is_numeric($value) ? (float)$value : $value,
			AggregateFunction::Count => (int)$value,
		};
	}

	/**
	 * Requested fields minus those hidden from the actor by CRM user-field visibility
	 * ({@see VisibilityManager}). getItemsFilteredByPermissions() personalizes the row set but not the
	 * field set, so a restricted UF value would otherwise leak into the slice. Empty select means
	 * "all schema fields" and is expanded before filtering. Standard relation keys (COMPANY_ID, …) are
	 * never user fields, so join keys survive the filter.
	 *
	 * @return string[]
	 */
	private function accessibleSelect(Factory $factory, ExtractQuery $query, int $actorId): array
	{
		$requested = $query->select === [] ? $this->schemaFieldNames($factory) : $query->select;

		if (!VisibilityManager::isEnabled())
		{
			return $requested;
		}

		return array_values(VisibilityManager::filterNotAccessibleFields(
			$factory->getEntityTypeId(),
			$requested,
			VisibilityManager::getUserAccessCodes($actorId),
		));
	}

	/**
	 * @return array<string, DateTime|null>
	 * @throws SourceUnavailableException
	 */
	private function dateWindowFilter(Factory $factory, ExtractQuery $query, string $entity): array
	{
		if (!$factory->isFieldExists(Item::FIELD_NAME_CREATED_TIME))
		{
			throw SourceUnavailableException::sourceDateWindowUnsupported($entity);
		}

		return [
			'>=' . Item::FIELD_NAME_CREATED_TIME => $query->getDateFrom(),
			'<' . Item::FIELD_NAME_CREATED_TIME => $query->getDateTo(),
		];
	}

	/**
	 * @return SourceRelation[]
	 * @throws SourceUnavailableException
	 */
	public function getRelations(SourceRef $source): array
	{
		$factory = $this->resolveFactory($source);

		$relations = [];
		foreach ($this->collectParentChildRelations($factory) as $relation)
		{
			$relations[$this->relationKey($relation)] = $relation;
		}

		foreach ($this->collectFieldRelations($factory) as $relation)
		{
			$relations[$this->relationKey($relation)] = $relation;
		}

		return array_values($relations);
	}

	/**
	 * @return SourceRelation[]
	 */
	private function collectParentChildRelations(Factory $factory): array
	{
		$relations = [];
		$collection = $this->container->getRelationManager()->getRelations($factory->getEntityTypeId());
		foreach ($collection as $relation)
		{
			$parentTypeId = $relation->getParentEntityTypeId();
			$childTypeId = $relation->getChildEntityTypeId();

			$parentEntity = \CCrmOwnerType::ResolveName($parentTypeId);
			$childEntity = \CCrmOwnerType::ResolveName($childTypeId);
			if ($parentEntity === '' || $childEntity === '')
			{
				continue;
			}

			$childFactory = $this->findFactory($childTypeId);
			if ($childFactory === null)
			{
				continue;
			}

			$bindingField = $this->findBindingField($childFactory, $parentTypeId);
			if ($bindingField === null)
			{
				continue;
			}

			$relations[] = new SourceRelation(
				fromEntity: $childEntity,
				fromField: $bindingField->getName(),
				toEntity: $parentEntity,
				toField: Item::FIELD_NAME_ID,
				title: (string)\CCrmOwnerType::GetDescription($parentTypeId),
			);
		}

		return $relations;
	}

	private function findBindingField(Factory $childFactory, int $parentTypeId): ?Field
	{
		$candidate = null;
		foreach ($childFactory->getFieldsCollection() as $field)
		{
			if (!$this->isScalarEntityReference($field) || $this->resolveRelatedEntityTypeId($field) !== $parentTypeId)
			{
				continue;
			}

			if (ParentFieldManager::isParentFieldName($field->getName()))
			{
				return $field;
			}

			$candidate ??= $field;
		}

		return $candidate;
	}

	/**
	 * @return SourceRelation[]
	 */
	private function collectFieldRelations(Factory $factory): array
	{
		$entity = \CCrmOwnerType::ResolveName($factory->getEntityTypeId());
		if ($entity === '')
		{
			return [];
		}

		$relations = [];
		foreach ($factory->getFieldsCollection() as $field)
		{
			if (!$this->isScalarEntityReference($field))
			{
				continue;
			}

			$relatedEntity = \CCrmOwnerType::ResolveName($this->resolveRelatedEntityTypeId($field));
			if ($relatedEntity === '')
			{
				continue;
			}

			$relations[] = new SourceRelation(
				fromEntity: $entity,
				fromField: $field->getName(),
				toEntity: $relatedEntity,
				toField: Item::FIELD_NAME_ID,
				title: $field->getTitle(),
			);
		}

		return $relations;
	}

	private function resolveRelatedEntityTypeId(Field $field): int
	{
		$fromSettings = $field->getSettings()['parentEntityTypeId'] ?? null;
		if (is_numeric($fromSettings))
		{
			return (int)$fromSettings;
		}

		if (ParentFieldManager::isParentFieldName($field->getName()))
		{
			return ParentFieldManager::getEntityTypeIdFromFieldName($field->getName());
		}

		return match ($field->getType())
		{
			Field::TYPE_CRM_LEAD => \CCrmOwnerType::Lead,
			Field::TYPE_CRM_DEAL => \CCrmOwnerType::Deal,
			Field::TYPE_CRM_CONTACT => \CCrmOwnerType::Contact,
			Field::TYPE_CRM_COMPANY => \CCrmOwnerType::Company,
			Field::TYPE_CRM_QUOTE => \CCrmOwnerType::Quote,
			default => \CCrmOwnerType::Undefined,
		};
	}

	private function relationKey(SourceRelation $relation): string
	{
		return implode('|', [
			$relation->fromEntity,
			$relation->fromField,
			$relation->toEntity,
			$relation->toField,
		]);
	}

	/**
	 * @throws SourceUnavailableException
	 */
	private function resolveFactory(SourceRef $source): Factory
	{
		$entityTypeId = \CCrmOwnerType::ResolveID($source->entity);

		$factory = $this->findFactory($entityTypeId);
		if ($factory === null)
		{
			throw SourceUnavailableException::sourceTypeRemoved($entityTypeId);
		}

		return $factory;
	}

	private function findFactory(int $entityTypeId): ?Factory
	{
		return \CCrmOwnerType::isUseFactoryBasedApproach($entityTypeId)
			? $this->container->getFactory($entityTypeId)
			: null
		;
	}

	/**
	 * @throws SourceUnavailableException
	 */
	private function assertRequestedFieldsExist(SourceRef $source, ExtractQuery $query): void
	{
		$requestedCodes = $query->select;

		$keyField = $query->hasKeyIn() ? $query->getKeyField() : null;
		if ($keyField !== null && $keyField !== '')
		{
			$requestedCodes[] = $keyField;
		}

		if ($requestedCodes === [])
		{
			return;
		}

		$schema = $this->getSourceSchema($source);
		foreach ($requestedCodes as $code)
		{
			if ($schema->getField((string)$code) === null)
			{
				throw SourceUnavailableException::sourceFieldRemoved((string)$code);
			}
		}
	}

	private function resolveFieldType(Field $field): string
	{
		if ($this->isScalarEntityReference($field))
		{
			return FieldType::INT;
		}

		return self::FIELD_TYPE_MAP[$field->getType()] ?? FieldType::STRING;
	}

	/**
	 * A join key filters the non-leading source by IN() on this field once per chunk, so only
	 * index-backed fields qualify. The primary key (ID) always is; a scalar entity reference
	 * (COMPANY_ID, CONTACT_ID, PARENT_ID_{typeId}) qualifies only when the physical table actually
	 * carries a leading index on its column — some references (e.g. b_crm_deal.MYCOMPANY_ID, QUOTE_ID)
	 * have none, and offering them as a key would rescan the whole table per chunk. Every other field
	 * (TITLE, DATE_MODIFY, user fields, free text) is refused as a key upstream.
	 */
	private function isIndexedJoinKey(Field $field, Factory $factory): bool
	{
		if ($field->getName() === Item::FIELD_NAME_ID)
		{
			return true;
		}

		return $this->isScalarEntityReference($field)
			&& $this->isLeadingIndexColumn($factory, $field->getName());
	}

	/**
	 * Whether {@see $column} is a leading index column of the entity's physical table, read from the
	 * live schema — not from a static assumption that every reference is indexed. An unindexed column
	 * is never offered as a join key.
	 */
	private function isLeadingIndexColumn(Factory $factory, string $column): bool
	{
		try
		{
			$dataClass = $factory->getDataClass();
			$tableName = $dataClass::getTableName();
		}
		catch (\Throwable)
		{
			return false;
		}

		$leadingColumns = $this->leadingIndexColumns($tableName);

		return isset($leadingColumns[$column]);
	}

	/**
	 * Leading columns of every index on the table, read in a single pass and memoized: a type with
	 * many reference fields then issues one introspection query instead of one SHOW INDEX per field.
	 * An empty result is memoized too, so a driver without a single-pass reader never re-probes.
	 *
	 * @return array<string, true>
	 */
	private function leadingIndexColumns(string $tableName): array
	{
		return $this->leadingIndexColumnsByTable[$tableName]
			??= $this->readLeadingIndexColumns($tableName);
	}

	/**
	 * One SHOW INDEX pass over the table, collecting the first column of each index (Seq_in_index = 1).
	 * Only MySQL exposes this shape; any other driver, or any failure, yields an empty set, so the field
	 * is treated as unindexed and never offered as a join key (fail-closed).
	 *
	 * @return array<string, true>
	 */
	private function readLeadingIndexColumns(string $tableName): array
	{
		$connection = Application::getConnection();
		if ($connection->getType() !== 'mysql')
		{
			return [];
		}

		$safeTable = (string)preg_replace('/[^A-Za-z0-9_]+/', '', $tableName);
		if ($safeTable === '')
		{
			return [];
		}

		$leadingColumns = [];
		try
		{
			$rows = $connection->query('SHOW INDEX FROM `' . $safeTable . '`');
			while ($row = $rows->fetch())
			{
				if ((int)($row['Seq_in_index'] ?? 0) === 1)
				{
					$leadingColumns[(string)$row['Column_name']] = true;
				}
			}
		}
		catch (\Throwable)
		{
			return [];
		}

		return $leadingColumns;
	}

	private function isScalarEntityReference(Field $field): bool
	{
		return
			!$field->isHidden()
			&& !$field->isMultiple()
			&& $this->resolveRelatedEntityTypeId($field) !== \CCrmOwnerType::Undefined
		;
	}

	/**
	 * @param string[] $select
	 * @return array<string, scalar|null|array>
	 */
	private function projectRow(Factory $factory, Item $item, array $select): array
	{
		$codes = $select === [] ? $this->schemaFieldNames($factory) : $select;

		$row = [];
		foreach ($codes as $code)
		{
			$code = (string)$code;
			$row[$code] = $item->hasField($code)
				? $this->normalizeValue($item->get($code))
				: null
			;
		}

		$row['id'] = $item->getId();

		return $row;
	}

	/** @return string[] */
	private function schemaFieldNames(Factory $factory): array
	{
		$names = [];
		foreach ($factory->getFieldsCollection() as $field)
		{
			if (!$field->isHidden())
			{
				$names[] = $field->getName();
			}
		}

		return $names;
	}

	private function normalizeValue(mixed $value): mixed
	{
		if (is_array($value))
		{
			return array_map(fn (mixed $element): mixed => $this->normalizeValue($element), $value);
		}

		if ($value instanceof DateTime)
		{
			return $value->format('Y-m-d H:i:s');
		}

		if ($value instanceof Date)
		{
			return $value->format('Y-m-d');
		}

		if ($value instanceof \Traversable)
		{
			return $this->normalizeValue(iterator_to_array($value, false));
		}

		if (is_object($value))
		{
			return method_exists($value, '__toString') ? (string)$value : null;
		}

		return $value;
	}
}

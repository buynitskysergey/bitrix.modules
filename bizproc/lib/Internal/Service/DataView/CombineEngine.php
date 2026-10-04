<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Entity\DataView\DataView;
use Bitrix\Bizproc\Internal\Exception\DataView\InvalidDataViewDefinitionException;
use Bitrix\Bizproc\Internal\Repository\DataViewRepository\DataViewRepositoryInterface;
use Bitrix\Bizproc\Internal\Service\DataView\Dto\AggregatePlan;
use Bitrix\Bizproc\Internal\Service\DataView\Dto\CombineResult;
use Bitrix\Bizproc\Internal\Service\DataView\Dto\CombineRow;
use Bitrix\Bizproc\Internal\Service\DataView\Dto\JoinKeyPair;
use Bitrix\Bizproc\Internal\Service\DataView\Dto\JoinPlan;
use Bitrix\Bizproc\Internal\Service\DataView\Dto\JoinPlanSide;
use Bitrix\Bizproc\Internal\Service\DataView\Dto\PlanColumn;
use Bitrix\Bizproc\Internal\Service\DataView\Dto\ProjectPlan;
use Bitrix\Bizproc\Internal\Service\DataView\Provider\StorageDataSourceProvider;
use Bitrix\Bizproc\Public\DataView\Dto\AggregateFunction;
use Bitrix\Bizproc\Public\DataView\Dto\AggregateFunctionCall;
use Bitrix\Bizproc\Public\DataView\Dto\AggregateQuery;
use Bitrix\Bizproc\Public\DataView\Dto\ExtractQuery;
use Bitrix\Bizproc\Public\DataView\Dto\SourceField;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;
use Bitrix\Bizproc\Public\DataView\Dto\SourceSchema;
use Bitrix\Bizproc\Public\DataView\Exception\DataVolumeLimitExceededException;
use Bitrix\Bizproc\Public\DataView\Exception\KeyNotIndexedException;
use Bitrix\Bizproc\Public\DataView\Exception\KeyNotJoinableException;
use Bitrix\Bizproc\Public\DataView\Exception\KeyTypeMismatchException;
use Bitrix\Bizproc\Public\DataView\Exception\RowLimitExceededException;
use Bitrix\Bizproc\Public\DataView\Exception\SourceUnavailableException;
use Bitrix\Bizproc\Public\DataView\Interface\AggregationCapableProvider;
use Bitrix\Bizproc\Public\DataView\Interface\DataSourceProvider;
use Bitrix\Bizproc\Public\DataView\Interface\StampConstantProvider;
use Bitrix\Bizproc\Public\DataView\Registry\DataSourceProviderRegistry;

final class CombineEngine
{
	private const KEY_GLUE = "\x1f";

	public const DEFAULT_ROW_LIMIT = 1000;

	public const AGGREGATE_INPUT_LIMIT = 10000;

	public const DEFAULT_DATA_BYTE_LIMIT = 33554432;

	private const VALUE_OVERHEAD_BYTES = 64;

	private const KEY_IN_CHUNK_SIZE = 500;

	private const OPERATION_AGGREGATE = 'aggregate';

	private const OPERATION_PROJECT = 'project';

	private readonly int $rowLimit;
	private readonly int $dataByteLimit;
	private int $bytesRemaining;

	/** @var array<int, true>|null */
	private ?array $dataViewTypeIds = null;

	public function __construct(
		private readonly DataSourceProviderRegistry $providerRegistry,
		private readonly PeriodResolver $periodResolver,
		private readonly DataViewLimitsService $limitsService,
		private readonly ?DataViewRepositoryInterface $dataViewRepository = null,
		?int $rowLimit = null,
		int $dataByteLimit = self::DEFAULT_DATA_BYTE_LIMIT,
		private readonly ?ValuePresenter $valuePresenter = null,
	) {
		$this->rowLimit = max(1, $rowLimit ?? $this->limitsService->getResultRowsLimit());
		$this->dataByteLimit = max(1, $dataByteLimit);
		$this->bytesRemaining = $this->dataByteLimit;
	}

	public function getRowLimit(): int
	{
		return $this->rowLimit;
	}

	/**
	 * @param bool $withComputedColumns formulas of the definition are applied to the whole result, as
	 *   materialization needs them; a preview turns them off to compute the page it shows instead
	 */
	public function combine(DataView $view, int $actorId, bool $withComputedColumns = true): CombineResult
	{
		$this->dataViewTypeIds = null;

		$definition = $view->getDefinition();

		$result = match ((string)($definition['operation'] ?? ''))
		{
			self::OPERATION_AGGREGATE => $this->combineAggregate($definition, $actorId),
			self::OPERATION_PROJECT => $this->combineProject($definition, $actorId),
			default => $this->combineJoin($definition, $actorId),
		};

		return $withComputedColumns ? $this->applyFormulas($definition, $result, $actorId) : $result;
	}

	/**
	 * Computed columns are the last step of a row. A row that fails to compute keeps an empty cell
	 * instead of failing the whole materialization. A formula asking for an output modifier is answered
	 * here as well, so the value written to the result storage is the value the preview showed.
	 *
	 * A computed cell is memory of its own — a formula may hold far more than the source value it
	 * replaces — so it is charged against the same budget the source rows are, and charged as computed:
	 * the raw value is what the run holds, while presentation may replace it with a shorter label.
	 */
	private function applyFormulas(array $definition, CombineResult $result, int $actorId): CombineResult
	{
		if ($result->isEmpty() || ColumnFormula::mapByColumnCode($definition) === [])
		{
			return $result;
		}

		$values = [];
		foreach ($result->getRows() as $index => $row)
		{
			$values[$index] = $row->values;
		}

		$values = $this->computeFormulas($definition, $values, $actorId);

		$rows = [];
		foreach ($result->getRows() as $index => $row)
		{
			$rows[] = new CombineRow($row->leftRecordId, $row->rightRecordId, $values[$index]);
		}

		return new CombineResult($rows);
	}

	/**
	 * Computed cells of the given rows, in the shape they are shown in. A preview computes the page it
	 * shows through here, so its cells are charged against the very budget the materialized ones are and
	 * a value inflating formula cannot answer a preview it would fail a materialization with.
	 *
	 * @param array<array-key, array<string, mixed>> $rows
	 * @return array<array-key, array<string, mixed>>
	 * @throws DataVolumeLimitExceededException
	 */
	public function computeFormulas(array $definition, array $rows, int $actorId): array
	{
		$evaluators = ColumnFormula::evaluatorsByColumnCode($definition);
		if ($rows === [] || $evaluators === [])
		{
			return $rows;
		}

		foreach ($rows as $index => $row)
		{
			$rows[$index] = ColumnFormula::applyToRow($evaluators, $row);
			$this->chargeDataBudget(array_intersect_key($rows[$index], $evaluators));
		}

		return $this->valuePresenter?->presentComputedColumns($definition, $rows, $actorId) ?? $rows;
	}

	/**
	 * @throws InvalidDataViewDefinitionException
	 * @throws KeyTypeMismatchException
	 * @throws RowLimitExceededException
	 * @throws SourceUnavailableException
	 */
	private function combineJoin(array $definition, int $actorId): CombineResult
	{
		$plan = $this->buildPlan($definition);

		$leadingProvider = $this->resolveProvider($plan->leading->ref);
		$otherProvider = $this->resolveProvider($plan->other->ref);

		$schemaByAlias = [
			$plan->leading->alias => $leadingProvider->getSourceSchema($plan->leading->ref),
			$plan->other->alias => $otherProvider->getSourceSchema($plan->other->ref),
		];
		$this->assertFieldsExist($plan, $schemaByAlias);
		$this->assertKeyTypesCompatible($plan, $schemaByAlias);

		$window = $this->periodResolver->resolve($definition['period']);
		$this->bytesRemaining = $this->dataByteLimit;

		$leadingRows = $this->extractLeadingRows(
			$leadingProvider,
			$plan,
			$actorId,
			$this->isDataViewSource($plan->leading->ref) ? null : $window,
		);

		$keyInValues = $this->collectFirstKeyValues($leadingRows, $plan->leading->keyFields[0]);
		if ($keyInValues === [])
		{
			return new CombineResult([]);
		}

		$hash = $this->extractOtherHash($otherProvider, $plan, $actorId, $keyInValues);

		$rows = [];
		foreach ($leadingRows as $leadingRow)
		{
			$key = $this->buildKey($leadingRow, $plan->leading->keyFields);
			if ($key === null || !isset($hash[$key]))
			{
				continue;
			}

			foreach ($hash[$key] as $otherRow)
			{
				$rows[] = $this->projectRow($plan, $leadingRow, $otherRow);
				if (count($rows) > $this->rowLimit)
				{
					throw RowLimitExceededException::limit($this->rowLimit);
				}
			}
		}

		return new CombineResult($rows);
	}

	/**
	 * @throws DataVolumeLimitExceededException
	 * @throws InvalidDataViewDefinitionException
	 * @throws RowLimitExceededException
	 * @throws SourceUnavailableException
	 */
	private function combineProject(array $definition, int $actorId): CombineResult
	{
		$plan = $this->buildProjectPlan($definition);
		$provider = $this->resolveProvider($plan->ref);

		$this->assertProjectFieldsExist($plan, $provider->getSourceSchema($plan->ref));

		$window = $this->periodResolver->resolve($definition['period']);
		$this->bytesRemaining = $this->dataByteLimit;

		$query = new ExtractQuery(
			limit: $this->rowLimit + 1,
			select: $this->buildProjectSelect($plan),
			dateWindow: $this->isDataViewSource($plan->ref) ? null : $window,
		);

		$result = [];
		foreach ($provider->extract($plan->ref, $query, $actorId) as $row)
		{
			if (count($result) >= $this->rowLimit)
			{
				throw RowLimitExceededException::limit($this->rowLimit);
			}

			$this->chargeDataBudget($row);

			$this->chargeStampBudget($plan->columns);

			$values = [];
			foreach ($plan->columns as $column)
			{
				$values[$column->code] = $column->isStamp
					? $column->value
					: ($row[$column->field] ?? null)
				;
			}

			$result[] = new CombineRow(
				leftRecordId: (int)($row['id'] ?? 0),
				rightRecordId: 0,
				values: $values,
			);
		}

		return new CombineResult($result);
	}

	/**
	 * @throws InvalidDataViewDefinitionException
	 */
	private function buildProjectPlan(array $definition): ProjectPlan
	{
		$byAlias = [];
		foreach ((array)($definition['sources'] ?? []) as $source)
		{
			$alias = (string)($source['alias'] ?? '');
			if ($alias !== '')
			{
				$byAlias[$alias] = (array)$source;
			}
		}
		if (count($byAlias) !== 1)
		{
			throw new InvalidDataViewDefinitionException('DataView project requires exactly one source.');
		}

		$alias = (string)array_key_first($byAlias);

		$columns = [];
		foreach ((array)($definition['columns'] ?? []) as $column)
		{
			$column = (array)$column;
			$code = (string)($column['code'] ?? '');
			if (($column['kind'] ?? null) === 'constant')
			{
				$columns[] = $this->resolveStampPlanColumn($column, $code);

				continue;
			}

			[$refAlias, $field] = $this->splitRef((string)($column['source'] ?? ''));
			if ($code === '' || $refAlias !== $alias)
			{
				throw new InvalidDataViewDefinitionException('DataView column must reference the project source alias.');
			}

			$columns[] = PlanColumn::source($code, $field);
		}

		return new ProjectPlan(SourceRef::fromArray($byAlias[$alias]), $columns);
	}

	/**
	 * @throws SourceUnavailableException
	 */
	private function assertProjectFieldsExist(ProjectPlan $plan, SourceSchema $schema): void
	{
		foreach ($plan->columns as $column)
		{
			if (!$column->isStamp && $schema->getField($column->field) === null)
			{
				throw SourceUnavailableException::sourceFieldRemoved($column->field);
			}
		}
	}

	/**
	 * @return string[]
	 */
	private function buildProjectSelect(ProjectPlan $plan): array
	{
		$fields = [];
		foreach ($plan->columns as $column)
		{
			if (!$column->isStamp)
			{
				$fields[] = $column->field;
			}
		}

		return array_values(array_unique($fields));
	}

	private function combineAggregate(array $definition, int $actorId): CombineResult
	{
		$plan = $this->buildAggregatePlan($definition);
		$provider = $this->resolveProvider($plan->ref);

		$window = $this->periodResolver->resolve($definition['period']);

		// One row over the limit so a provider that caps its result at query.limit still yields the
		// extra group that aggregateByProvider() needs to detect overflow instead of silently truncating.
		$query = new AggregateQuery(
			$plan->groupBy,
			$plan->functions,
			$this->rowLimit + 1,
			$this->isDataViewSource($plan->ref) ? null : $window,
		);

		$schema = $provider->getSourceSchema($plan->ref);
		$this->assertAggregateFieldsExist($query, $schema);

		$this->bytesRemaining = $this->dataByteLimit;

		$rows = $provider instanceof AggregationCapableProvider
			? $this->aggregateByProvider($provider, $plan->ref, $query, $actorId)
			: $this->aggregateInPhp($provider, $plan->ref, $query, $actorId, $this->numericAggregateColumns($schema, $query))
		;

		$stampValues = $plan->stampValues();
		if ($stampValues !== [])
		{
			$rows = array_map(
				static fn (CombineRow $row): CombineRow => new CombineRow(
					$row->leftRecordId,
					$row->rightRecordId,
					array_merge($row->values, $stampValues),
				),
				$rows,
			);
		}

		return new CombineResult($rows);
	}

	private function buildAggregatePlan(array $definition): AggregatePlan
	{
		$byAlias = [];
		foreach ((array)($definition['sources'] ?? []) as $source)
		{
			$alias = (string)($source['alias'] ?? '');
			if ($alias !== '')
			{
				$byAlias[$alias] = (array)$source;
			}
		}
		if (count($byAlias) !== 1)
		{
			throw new InvalidDataViewDefinitionException('DataView aggregate requires exactly one source.');
		}

		$alias = (string)array_key_first($byAlias);
		$aggregate = (array)($definition['aggregate'] ?? []);

		$groupBy = [];
		foreach ((array)($aggregate['groupBy'] ?? []) as $ref)
		{
			$groupBy[] = $this->splitAggregateRef((string)$ref, $alias);
		}
		if ($groupBy === [])
		{
			throw new InvalidDataViewDefinitionException('DataView aggregate requires at least one groupBy field.');
		}

		$functions = [];
		foreach ((array)($aggregate['functions'] ?? []) as $function)
		{
			$function = (array)$function;
			$fn = AggregateFunction::tryFrom(strtoupper((string)($function['fn'] ?? '')));
			$code = (string)($function['code'] ?? '');
			if ($fn === null || $code === '')
			{
				throw new InvalidDataViewDefinitionException('DataView aggregate function requires a known "fn" and a "code".');
			}

			$functions[] = new AggregateFunctionCall(
				column: $this->splitAggregateRef((string)($function['column'] ?? ''), $alias),
				fn: $fn,
				code: $code,
			);
		}
		if ($functions === [])
		{
			throw new InvalidDataViewDefinitionException('DataView aggregate requires at least one function.');
		}

		$stamps = [];
		foreach ((array)($definition['columns'] ?? []) as $column)
		{
			$column = (array)$column;
			if (($column['kind'] ?? null) === 'constant')
			{
				$stamps[] = $this->resolveStampPlanColumn($column, (string)($column['code'] ?? ''));
			}
		}

		return new AggregatePlan(
			ref: SourceRef::fromArray($byAlias[$alias]),
			groupBy: $groupBy,
			functions: $functions,
			stamps: $stamps,
		);
	}

	private function splitAggregateRef(string $ref, string $alias): string
	{
		[$refAlias, $field] = $this->splitRef($ref);
		if ($refAlias !== $alias)
		{
			throw new InvalidDataViewDefinitionException(
				sprintf('DataView aggregate field "%s" must reference the source alias "%s".', $ref, $alias)
			);
		}

		return $field;
	}

	private function assertAggregateFieldsExist(AggregateQuery $query, SourceSchema $schema): void
	{
		foreach ($query->getUsedColumns() as $field)
		{
			if ($schema->getField($field) === null)
			{
				throw SourceUnavailableException::sourceFieldRemoved($field);
			}
		}
	}

	/**
	 * @param array<string, true> $numericColumns aggregate columns compared numerically in MIN/MAX
	 */
	private function aggregateInPhp(
		DataSourceProvider $provider,
		SourceRef $ref,
		AggregateQuery $query,
		int $actorId,
		array $numericColumns,
	): array {
		$aggregateInputLimit = $this->limitsService->getAggregateInputLimit();
		$extractQuery = new ExtractQuery(
			limit: $aggregateInputLimit + 1,
			select: $query->getUsedColumns(),
			dateWindow: $query->hasDateWindow()
				? ['from' => $query->getDateFrom(), 'to' => $query->getDateTo()]
				: null,
		);

		$groups = [];
		$inputCount = 0;
		foreach ($provider->extract($ref, $extractQuery, $actorId) as $row)
		{
			$inputCount++;
			if ($inputCount > $aggregateInputLimit)
			{
				throw RowLimitExceededException::aggregateInputLimit($aggregateInputLimit);
			}

			$this->chargeDataBudget($row);

			$key = $this->buildKey($row, $query->groupBy);
			if ($key === null)
			{
				continue;
			}

			if (!isset($groups[$key]))
			{
				if (count($groups) >= $this->rowLimit)
				{
					throw RowLimitExceededException::limit($this->rowLimit);
				}

				$groupValues = [];
				foreach ($query->groupBy as $field)
				{
					$groupValues[$field] = $row[$field] ?? null;
				}
				$groups[$key] = [
					'values' => $groupValues,
					'accumulators' => $this->initFunctionAccumulators($query->functions),
				];
			}

			foreach ($query->functions as $function)
			{
				$this->accumulateFunction(
					$groups[$key]['accumulators'][$function->code],
					$function,
					$row,
					isset($numericColumns[$function->column]),
				);
			}
		}

		$result = [];
		foreach ($groups as $key => $group)
		{
			$values = $group['values'];
			foreach ($query->functions as $function)
			{
				$values[$function->code] = $this->finalizeFunction(
					$group['accumulators'][$function->code],
					$function,
				);
			}

			$result[] = $this->aggregateRow((string)$key, $values);
		}

		return $result;
	}

	private function aggregateByProvider(
		AggregationCapableProvider $provider,
		SourceRef $ref,
		AggregateQuery $query,
		int $actorId,
	): array {
		$result = [];
		foreach ($provider->aggregate($ref, $query, $actorId) as $row)
		{
			$row = (array)$row;
			$this->chargeDataBudget($row);

			$key = $this->buildKey($row, $query->groupBy);
			if ($key === null)
			{
				continue;
			}

			$values = [];
			foreach ($query->groupBy as $field)
			{
				$values[$field] = $row[$field] ?? null;
			}
			foreach ($query->functions as $function)
			{
				$value = $row[$function->code] ?? null;
				if ($value !== null && $function->fn === AggregateFunction::Avg)
				{
					$value = (float)$value;
				}

				$values[$function->code] = $value;
			}

			$result[] = $this->aggregateRow($key, $values);
			if (count($result) > $this->rowLimit)
			{
				throw RowLimitExceededException::limit($this->rowLimit);
			}
		}

		return $result;
	}

	private function aggregateRow(string $groupKey, array $values): CombineRow
	{
		$digest = md5($groupKey);

		return new CombineRow(
			leftRecordId: (int)hexdec(substr($digest, 0, 8)),
			rightRecordId: (int)hexdec(substr($digest, 8, 8)),
			values: $values,
		);
	}

	/**
	 * @param AggregateFunctionCall[] $functions
	 * @return array<string, array{count: int, sum: int|float, min: int|float|string|null, max: int|float|string|null}>
	 */
	private function initFunctionAccumulators(array $functions): array
	{
		$accumulators = [];
		foreach ($functions as $function)
		{
			$accumulators[$function->code] = ['count' => 0, 'sum' => 0, 'min' => null, 'max' => null];
		}

		return $accumulators;
	}

	/**
	 * Folds a single row into the running accumulator, mirroring the value handling of the former
	 * buffered applyFunction(): null and array values are ignored, COUNT counts the remaining
	 * values, and SUM/AVG/MIN/MAX aggregate only over them.
	 *
	 * @param array{count: int, sum: int|float, min: int|float|string|null, max: int|float|string|null} $accumulator
	 * @param bool $numeric compare MIN/MAX by numeric value; otherwise lexicographically
	 */
	private function accumulateFunction(array &$accumulator, AggregateFunctionCall $function, array $row, bool $numeric): void
	{
		$value = $row[$function->column] ?? null;
		if ($value === null || is_array($value))
		{
			return;
		}

		$accumulator['count']++;

		switch ($function->fn)
		{
			case AggregateFunction::Sum:
			case AggregateFunction::Avg:
				$accumulator['sum'] += $value;
				break;
			case AggregateFunction::Min:
				$accumulator['min'] = $accumulator['min'] === null
					|| $this->compareValues($value, $accumulator['min'], $numeric) < 0
						? $value
						: $accumulator['min'];
				break;
			case AggregateFunction::Max:
				$accumulator['max'] = $accumulator['max'] === null
					|| $this->compareValues($value, $accumulator['max'], $numeric) > 0
						? $value
						: $accumulator['max'];
				break;
			case AggregateFunction::Count:
				break;
		}
	}

	/**
	 * @param array<string, true> $numericColumns
	 */
	private function numericAggregateColumns(SourceSchema $schema, AggregateQuery $query): array
	{
		$numericColumns = [];
		foreach ($query->functions as $function)
		{
			$field = $schema->getField($function->column);
			if ($field !== null && ($field->type === FieldType::INT || $field->type === FieldType::DOUBLE))
			{
				$numericColumns[$function->column] = true;
			}
		}

		return $numericColumns;
	}

	/**
	 * MIN/MAX in the PHP fallback: numeric columns compare by value, everything else
	 * lexicographically, so `"10"` vs `"2"` folds the same way a database would order that column
	 * type — independent of which provider served the source.
	 */
	private function compareValues(mixed $a, mixed $b, bool $numeric): int
	{
		if ($numeric)
		{
			return (float)$a <=> (float)$b;
		}

		return strcmp((string)$a, (string)$b);
	}

	/**
	 * @param array{count: int, sum: int|float, min: int|float|string|null, max: int|float|string|null} $accumulator
	 */
	private function finalizeFunction(array $accumulator, AggregateFunctionCall $function): int|float|string|null
	{
		if ($function->fn === AggregateFunction::Count)
		{
			return $accumulator['count'];
		}

		if ($accumulator['count'] === 0)
		{
			return null;
		}

		return match ($function->fn)
		{
			AggregateFunction::Sum => $accumulator['sum'],
			AggregateFunction::Avg => (float)($accumulator['sum'] / $accumulator['count']),
			AggregateFunction::Min => $accumulator['min'],
			AggregateFunction::Max => $accumulator['max'],
		};
	}

	private function buildPlan(array $definition): JoinPlan
	{
		$byAlias = [];
		foreach ((array)($definition['sources'] ?? []) as $source)
		{
			$alias = (string)($source['alias'] ?? '');
			if ($alias !== '')
			{
				$byAlias[$alias] = (array)$source;
			}
		}
		if (count($byAlias) !== 2)
		{
			throw new InvalidDataViewDefinitionException('DataView requires two sources with distinct aliases.');
		}

		$joinKeys = (array)($definition['joinKeys'] ?? []);
		if ($joinKeys === [])
		{
			throw new InvalidDataViewDefinitionException('DataView requires at least one join key.');
		}

		$leftAlias = null;
		$rightAlias = null;
		$leftKeyFields = [];
		$rightKeyFields = [];
		$keyPairs = [];
		foreach ($joinKeys as $pair)
		{
			[$la, $lf] = $this->splitRef((string)($pair['left'] ?? ''));
			[$ra, $rf] = $this->splitRef((string)($pair['right'] ?? ''));

			$leftAlias ??= $la;
			$rightAlias ??= $ra;
			if ($la !== $leftAlias || $ra !== $rightAlias)
			{
				throw new InvalidDataViewDefinitionException('DataView join keys must reference a single alias per side.');
			}

			$leftKeyFields[] = $lf;
			$rightKeyFields[] = $rf;
			$keyPairs[] = new JoinKeyPair($lf, $rf);
		}

		if ($leftAlias === $rightAlias || !isset($byAlias[$leftAlias], $byAlias[$rightAlias]))
		{
			throw new InvalidDataViewDefinitionException('DataView join keys must reference both distinct sources.');
		}

		$leadingAlias = (string)($definition['period']['sourceAlias'] ?? '');
		if ($leadingAlias === $leftAlias)
		{
			[$leadingKeyFields, $otherKeyFields] = [$leftKeyFields, $rightKeyFields];
			$otherAlias = $rightAlias;
		}
		elseif ($leadingAlias === $rightAlias)
		{
			[$leadingKeyFields, $otherKeyFields] = [$rightKeyFields, $leftKeyFields];
			$otherAlias = $leftAlias;
		}
		else
		{
			throw new InvalidDataViewDefinitionException('DataView period.sourceAlias must match a join key alias.');
		}

		return new JoinPlan(
			leftAlias: $leftAlias,
			rightAlias: $rightAlias,
			leading: new JoinPlanSide(
				SourceRef::fromArray($byAlias[$leadingAlias]),
				$leadingAlias,
				$leadingKeyFields,
			),
			other: new JoinPlanSide(
				SourceRef::fromArray($byAlias[$otherAlias]),
				$otherAlias,
				$otherKeyFields,
			),
			keyPairs: $keyPairs,
			columns: $this->parseColumns($definition, $leftAlias, $rightAlias),
		);
	}

	/**
	 * @return PlanColumn[]
	 * @throws InvalidDataViewDefinitionException
	 */
	private function parseColumns(array $definition, string $leftAlias, string $rightAlias): array
	{
		$columns = [];
		foreach ((array)($definition['columns'] ?? []) as $column)
		{
			$column = (array)$column;
			$code = (string)($column['code'] ?? '');
			if (($column['kind'] ?? null) === 'constant')
			{
				$columns[] = $this->resolveStampPlanColumn($column, $code);

				continue;
			}

			[$alias, $field] = $this->splitRef((string)($column['source'] ?? ''));
			if ($code === '' || ($alias !== $leftAlias && $alias !== $rightAlias))
			{
				throw new InvalidDataViewDefinitionException('DataView column must reference a known source alias.');
			}

			$columns[] = PlanColumn::source($code, $field, $alias);
		}

		return $columns;
	}

	/**
	 * Collects field codes required on the given plan side: join keys plus projected columns.
	 *
	 * @return string[]
	 */
	private function buildExtractSelect(JoinPlan $plan, JoinPlanSide $side): array
	{
		$fields = $side->keyFields;
		foreach ($plan->columns as $column)
		{
			if (!$column->isStamp && $column->alias === $side->alias)
			{
				$fields[] = $column->field;
			}
		}

		return array_values(array_unique($fields));
	}

	/**
	 * @return array{0: string, 1: string} [alias, field]
	 * @throws InvalidDataViewDefinitionException
	 */
	private function splitRef(string $ref): array
	{
		$dot = strpos($ref, '.');
		if ($dot === false || $dot === 0 || $dot === strlen($ref) - 1)
		{
			throw new InvalidDataViewDefinitionException(sprintf('Invalid DataView field reference "%s".', $ref));
		}

		return [substr($ref, 0, $dot), substr($ref, $dot + 1)];
	}

	private function resolveProvider(SourceRef $ref): DataSourceProvider
	{
		$provider = $this->providerRegistry->get($ref->module);
		if ($provider === null)
		{
			throw new SourceUnavailableException(
				sprintf('DataView source provider for module "%s" is unavailable', $ref->module),
			);
		}

		return $provider;
	}

	/**
	 * A data view source is materialized whole: it holds its own already-windowed slice, so the
	 * consumer must read it in full and must not re-apply the period window on top (D1).
	 */
	private function isDataViewSource(SourceRef $ref): bool
	{
		if (
			$ref->module !== StorageDataSourceProvider::MODULE_ID
			|| $ref->entity !== StorageDataSourceProvider::ENTITY
		)
		{
			return false;
		}

		$storageTypeId = (int)$ref->getParam('storageTypeId', 0);

		return $storageTypeId > 0 && isset($this->dataViewTypeIds()[$storageTypeId]);
	}

	/**
	 * @return array<int, true> known data view storage type ids as a lookup set
	 */
	private function dataViewTypeIds(): array
	{
		if ($this->dataViewTypeIds === null)
		{
			$ids = $this->dataViewRepository?->getStorageTypeIds() ?? [];
			$this->dataViewTypeIds = array_fill_keys(array_map('intval', $ids), true);
		}

		return $this->dataViewTypeIds;
	}

	private function assertFieldsExist(JoinPlan $plan, array $schemaByAlias): void
	{
		$neededByAlias = [
			$plan->leftAlias => [],
			$plan->rightAlias => [],
		];
		foreach ($plan->keyPairs as $pair)
		{
			$neededByAlias[$plan->leftAlias][] = $pair->left;
			$neededByAlias[$plan->rightAlias][] = $pair->right;
		}
		foreach ($plan->columns as $column)
		{
			if (!$column->isStamp)
			{
				$neededByAlias[$column->alias][] = $column->field;
			}
		}

		foreach ($neededByAlias as $alias => $fields)
		{
			$schema = $schemaByAlias[$alias];
			foreach (array_unique($fields) as $field)
			{
				if ($schema->getField($field) === null)
				{
					throw SourceUnavailableException::sourceFieldRemoved($field);
				}
			}
		}
	}

	private function assertKeyTypesCompatible(JoinPlan $plan, array $schemaByAlias): void
	{
		$leftSchema = $schemaByAlias[$plan->leftAlias];
		$rightSchema = $schemaByAlias[$plan->rightAlias];

		foreach ($plan->keyPairs as $pair)
		{
			$leftField = $leftSchema->getField($pair->left);
			$rightField = $rightSchema->getField($pair->right);

			$this->assertJoinable($plan->leftAlias . '.' . $pair->left, $leftField);
			$this->assertJoinable($plan->rightAlias . '.' . $pair->right, $rightField);

			if ($this->keyCategory($leftField->type) !== $this->keyCategory($rightField->type))
			{
				throw KeyTypeMismatchException::forKeys(
					$plan->leftAlias . '.' . $pair->left,
					$leftField->type,
					$plan->rightAlias . '.' . $pair->right,
					$rightField->type,
				);
			}
		}
	}

	private function assertJoinable(string $key, SourceField $field): void
	{
		if (!$field->joinable)
		{
			throw KeyNotJoinableException::forKey($key, $field->type);
		}

		if (!$field->indexed)
		{
			throw KeyNotIndexedException::forKey($key, $field->type);
		}
	}

	private function keyCategory(string $type): string
	{
		return match ($type)
		{
			FieldType::INT, FieldType::DOUBLE => 'number',
			FieldType::DATE => 'date',
			FieldType::DATETIME => 'datetime',
			FieldType::TIME => 'time',
			default => 'string',
		};
	}

	/**
	 * @return array<int, array<string, scalar|null|array>>
	 * @throws SourceUnavailableException
	 */
	private function extractLeadingRows(
		DataSourceProvider $provider,
		JoinPlan $plan,
		int $actorId,
		?array $window,
	): array
	{
		$query = new ExtractQuery(
			limit: $this->rowLimit + 1,
			select: $this->buildExtractSelect($plan, $plan->leading),
			dateWindow: $window,
		);

		$rows = [];
		foreach ($provider->extract($plan->leading->ref, $query, $actorId) as $row)
		{
			if (count($rows) >= $this->rowLimit)
			{
				throw RowLimitExceededException::limit($this->rowLimit);
			}

			$this->chargeDataBudget($row);
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Distinct values of the first join key on the leading side. Rows whose first key part is
	 * null or multiple can never match ({@see buildKey}), so their values are not collected.
	 *
	 * @param array<int, array<string, scalar|null|array>> $leadingRows
	 * @return array<int, scalar>
	 */
	private function collectFirstKeyValues(array $leadingRows, string $keyField): array
	{
		$values = [];
		foreach ($leadingRows as $row)
		{
			$value = $row[$keyField] ?? null;
			if ($value !== null && !is_array($value))
			{
				$values[(string)$value] = $value;
			}
		}

		return array_values($values);
	}

	/**
	 * Streams the non-leading source straight into the probe hash. The source is narrowed to
	 * rows whose first join key occurs on the leading side (the key list is queried in chunks
	 * of {@see KEY_IN_CHUNK_SIZE} to keep the IN clause bounded), so an oversized second source
	 * is neither read nor held in full; composite keys are still matched exactly by the hash.
	 *
	 * @param array<int, scalar> $keyInValues
	 * @return array<string, array<int, array<string, scalar|null|array>>>
	 * @throws SourceUnavailableException
	 */
	private function extractOtherHash(
		DataSourceProvider $provider,
		JoinPlan $plan,
		int $actorId,
		array $keyInValues,
	): array
	{
		$hash = [];
		$count = 0;
		foreach (array_chunk($keyInValues, self::KEY_IN_CHUNK_SIZE) as $chunk)
		{
			$query = new ExtractQuery(
				limit: $this->rowLimit + 1,
				select: $this->buildExtractSelect($plan, $plan->other),
				keyIn: ['field' => $plan->other->keyFields[0], 'values' => $chunk],
			);

			foreach ($provider->extract($plan->other->ref, $query, $actorId) as $row)
			{
				if (++$count > $this->rowLimit)
				{
					throw RowLimitExceededException::limit($this->rowLimit);
				}

				$this->chargeDataBudget($row);

				$key = $this->buildKey($row, $plan->other->keyFields);
				if ($key !== null)
				{
					$hash[$key][] = $row;
				}
			}
		}

		return $hash;
	}

	/**
	 * The row limit alone does not bound memory: a source allows dozens of fields including
	 * TEXT and multiple values, so a thousand wide rows can exhaust PHP memory. Values are
	 * approximated by string length plus a fixed per-value overhead against a shared budget
	 * covering both sources of one combine run.
	 */
	private function chargeDataBudget(mixed $value): void
	{
		$this->bytesRemaining -= $this->measureValue($value);
		if ($this->bytesRemaining < 0)
		{
			throw DataVolumeLimitExceededException::bytes($this->dataByteLimit);
		}
	}

	/**
	 * Stamp values never come from a provider row, so the per-row charge does not see them,
	 * while every emitted row still carries its own copy.
	 *
	 * @param PlanColumn[] $columns
	 */
	private function chargeStampBudget(array $columns): void
	{
		foreach ($columns as $column)
		{
			if ($column->isStamp)
			{
				$this->chargeDataBudget($column->value);
			}
		}
	}

	private function measureValue(mixed $value): int
	{
		if (is_array($value))
		{
			$bytes = self::VALUE_OVERHEAD_BYTES;
			foreach ($value as $item)
			{
				$bytes += $this->measureValue($item);
			}

			return $bytes;
		}

		return self::VALUE_OVERHEAD_BYTES + (is_string($value) ? strlen($value) : 0);
	}

	/**
	 * Builds the hash key of a composite join key. Each part carries its own length because the
	 * glue byte is a legal character inside a string value: plain concatenation would give
	 * ['a', "b\x1fc"] and ["a\x1fb", 'c'] the same key and join unrelated rows.
	 */
	private function buildKey(array $row, array $keyFields): ?string
	{
		$parts = [];
		foreach ($keyFields as $field)
		{
			$value = $row[$field] ?? null;
			if ($value === null || is_array($value))
			{
				return null;
			}

			$value = (string)$value;
			$parts[] = strlen($value) . ':' . $value;
		}

		return implode(self::KEY_GLUE, $parts);
	}

	/**
	 * @param array<string, scalar|null|array> $leadingRow
	 * @param array<string, scalar|null|array> $otherRow
	 */
	private function projectRow(JoinPlan $plan, array $leadingRow, array $otherRow): CombineRow
	{
		if ($plan->isLeadingLeft())
		{
			$leftRow = $leadingRow;
			$rightRow = $otherRow;
		}
		else
		{
			$leftRow = $otherRow;
			$rightRow = $leadingRow;
		}

		$rowsByAlias = [
			$plan->leftAlias => $leftRow,
			$plan->rightAlias => $rightRow,
		];

		$this->chargeStampBudget($plan->columns);

		$values = [];
		foreach ($plan->columns as $column)
		{
			$values[$column->code] = $column->isStamp
				? $column->value
				: ($rowsByAlias[$column->alias][$column->field] ?? null)
			;
		}

		return new CombineRow(
			leftRecordId: (int)($leftRow['id'] ?? 0),
			rightRecordId: (int)($rightRow['id'] ?? 0),
			values: $values,
		);
	}

	private function resolveStampPlanColumn(array $column, string $code): PlanColumn
	{
		if ($code === '')
		{
			throw new InvalidDataViewDefinitionException('DataView stamp column requires a code.');
		}

		$ref = SourceRef::fromArray((array)($column['constant'] ?? []));
		$provider = $this->resolveProvider($ref);
		if (!$provider instanceof StampConstantProvider)
		{
			throw new SourceUnavailableException(
				sprintf('DataView source provider for module "%s" does not support stamp constants', $ref->module),
			);
		}

		return PlanColumn::stamp($code, $provider->resolveStampConstant($ref)->value);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView;

use Bitrix\Bizproc\Internal\Service\DocumentField\FieldValueFormatter;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;
use Bitrix\Bizproc\Public\DataView\Dto\SourceSchema;
use Bitrix\Bizproc\Public\DataView\Interface\PresentableProvider;
use Bitrix\Bizproc\Public\DataView\Registry\DataSourceProviderRegistry;

/**
 * Presentation of a row of a data view. Values stay as the sources gave them: a column is shown in a
 * printable shape only where the definition asks for it — a stamp constant, which has a type but no
 * source of its own, and a column whose formula carries an output modifier.
 */
final class ValuePresenter
{
	private const PRINTABLE_FORMAT = 'printable';

	public function __construct(
		private readonly DataSourceProviderRegistry $registry,
	) {
	}

	/**
	 * Cells a row shows in a printable shape while storing them raw — the stamp constants. A computed
	 * column is presented on the way into the row itself ({@see presentComputedColumns}), so a row read
	 * from the result storage already carries it in the shape its formula asked for.
	 *
	 * @param array $definition definition with 'sources' and 'columns'
	 * @param array<int|string, array<string, mixed>> $rows keyed by column code
	 * @return array<int|string, array<string, string>> overlay of changed cells, keyed by column code
	 */
	public function present(array $definition, array $rows): array
	{
		if ($rows === [])
		{
			return [];
		}

		$overlay = [];
		$this->applyStampOverlay($definition, $rows, $overlay);

		return $overlay;
	}

	/**
	 * Rows whose computed columns are in the shape their formula asks for. The preview and the
	 * materialization pass their rows through this one place, so the row the author saw and the row the
	 * result storage keeps cannot differ — and the string type the resolver gives such a column holds
	 * the label it promises.
	 *
	 * Presentation reaches out to sources and may fail on a source that has meanwhile become
	 * unavailable: the rows then keep their raw values instead of failing as a whole.
	 *
	 * @param array<int|string, array<string, mixed>> $rows keyed by column code
	 * @param int $actorId the actor the rows were extracted for, so a label follows the same permissions
	 * @return array<int|string, array<string, mixed>> the same rows with the presented cells replaced
	 */
	public function presentComputedColumns(array $definition, array $rows, int $actorId = 0): array
	{
		if ($rows === [])
		{
			return $rows;
		}

		$overlay = [];

		try
		{
			$this->applyFormulaOverlay($definition, $rows, $overlay, $actorId);
		}
		catch (\Throwable)
		{
			return $rows;
		}

		foreach ($overlay as $rowKey => $cells)
		{
			foreach ($cells as $columnCode => $label)
			{
				$rows[$rowKey][$columnCode] = $label;
			}
		}

		return $rows;
	}

	/**
	 * A formula that is a single reference with an output modifier ({=Column:CODE > printable}) keeps
	 * the referenced value as is and asks for it to be shown in that shape. A printable modifier over a
	 * field the source itself can present (a stage, a user, a category) is answered by that source;
	 * every other modifier is applied over the field type of the column the value came from. A computed
	 * formula holds a string of its own and needs no presentation.
	 *
	 * @param array<int|string, array<string, mixed>> $rows
	 * @param array<int|string, array<string, string>> $overlay
	 */
	private function applyFormulaOverlay(array $definition, array $rows, array &$overlay, int $actorId): void
	{
		$columnsByCode = [];
		foreach ((array)($definition['columns'] ?? []) as $column)
		{
			$column = (array)$column;
			$code = (string)($column['code'] ?? '');
			if ($code !== '')
			{
				$columnsByCode[$code] = $column;
			}
		}

		$sourceRefsByAlias = $this->indexSourceRefsByAlias($definition);
		$countCodes = $this->countFunctionCodes($definition);
		$schemasByAlias = [];
		$providerColumns = [];

		foreach (ColumnFormula::mapByColumnCode($definition) as $columnCode => $formula)
		{
			$reference = ColumnFormula::pureReference($formula);
			if ($reference === null || $reference['format'] === '')
			{
				continue;
			}

			$referenced = $columnsByCode[$reference['code']] ?? null;
			if ($referenced === null)
			{
				continue;
			}

			$presentableField = $reference['format'] === self::PRINTABLE_FORMAT && !isset($countCodes[$reference['code']])
				? $this->resolvePresentableField($referenced, $sourceRefsByAlias, $schemasByAlias)
				: null
			;

			if ($presentableField === null)
			{
				$this->applyFormatterOverlay($referenced, $reference['format'], $columnCode, $rows, $overlay);

				continue;
			}

			$providerColumns[$presentableField['alias']][$columnCode] = $presentableField['field'];
		}

		foreach ($providerColumns as $alias => $columnCodeToFieldCode)
		{
			$this->applyProviderOverlay($sourceRefsByAlias[$alias], $rows, $columnCodeToFieldCode, $overlay, $actorId);
		}
	}

	/**
	 * @param array<string, mixed> $referenced column the value was taken from
	 * @param array<int|string, array<string, mixed>> $rows
	 * @param array<int|string, array<string, string>> $overlay
	 */
	private function applyFormatterOverlay(
		array $referenced,
		string $format,
		string $columnCode,
		array $rows,
		array &$overlay,
	): void
	{
		$formatter = FieldValueFormatter::forType(
			(string)($referenced['sourceType'] ?? $referenced['type'] ?? ''),
			(bool)($referenced['multiple'] ?? false),
		);
		if ($formatter === null)
		{
			return;
		}

		FieldValueFormatter::prefetchUsers(array_column($rows, $columnCode));

		foreach ($rows as $rowKey => $row)
		{
			if (array_key_exists($columnCode, $row))
			{
				$overlay[$rowKey][$columnCode] = $formatter->format($row[$columnCode], $format);
			}
		}
	}

	/**
	 * The source field a column takes its value from, when that source declares the field presentable.
	 * The schema of an alias is asked for once per call: the presenter is a shared service and the same
	 * alias points at different sources between calls, so the memo belongs to the caller.
	 *
	 * @param array<string, mixed> $column
	 * @param array<string, SourceRef> $sourceRefsByAlias
	 * @param array<string, SourceSchema|null> $schemasByAlias memo filled as aliases are resolved
	 * @return array{alias: string, field: string}|null
	 */
	private function resolvePresentableField(array $column, array $sourceRefsByAlias, array &$schemasByAlias): ?array
	{
		$source = (string)($column['source'] ?? '');
		$dot = strpos($source, '.');
		if ($dot === false)
		{
			return null;
		}

		$alias = substr($source, 0, $dot);
		$fieldCode = substr($source, $dot + 1);
		$sourceRef = $sourceRefsByAlias[$alias] ?? null;
		if ($sourceRef === null || $fieldCode === '')
		{
			return null;
		}

		$schemasByAlias[$alias] ??= $this->loadSourceSchema($sourceRef);
		$field = $schemasByAlias[$alias]?->getField($fieldCode);

		return $field !== null && $field->presentable ? ['alias' => $alias, 'field' => $fieldCode] : null;
	}

	/**
	 * A source that has meanwhile become unavailable costs its own columns the printable shape, not the
	 * whole table its overlay.
	 */
	private function loadSourceSchema(SourceRef $sourceRef): ?SourceSchema
	{
		$provider = $this->registry->get($sourceRef->module);
		if (!$provider instanceof PresentableProvider)
		{
			return null;
		}

		try
		{
			return $provider->getSourceSchema($sourceRef);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * A stamp column holds a constant instead of a source field, so no provider owns its value: it is
	 * formatted by the field type the column was resolved with.
	 *
	 * @param array<int|string, array<string, mixed>> $rows
	 * @param array<int|string, array<string, string>> $overlay
	 */
	private function applyStampOverlay(array $definition, array $rows, array &$overlay): void
	{
		foreach ($this->buildStampFormatters($definition) as $columnCode => $formatter)
		{
			foreach ($rows as $rowKey => $row)
			{
				if (array_key_exists($columnCode, $row))
				{
					$overlay[$rowKey][$columnCode] = $formatter->format($row[$columnCode]);
				}
			}
		}
	}

	/** @return array<string, FieldValueFormatter> */
	private function buildStampFormatters(array $definition): array
	{
		$formatters = [];
		foreach ((array)($definition['columns'] ?? []) as $column)
		{
			$column = (array)$column;
			$columnCode = (string)($column['code'] ?? '');
			$type = (string)($column['type'] ?? '');
			if (($column['kind'] ?? null) !== 'constant' || $columnCode === '' || $type === '')
			{
				continue;
			}

			$formatter = FieldValueFormatter::forType($type);
			if ($formatter !== null)
			{
				$formatters[$columnCode] = $formatter;
			}
		}

		return $formatters;
	}

	/**
	 * Provider rows are keyed by field code, so several result columns built on one source field
	 * (SUM and AVG over the same field) must be presented in separate calls to keep their identity.
	 *
	 * @param array<string, string> $columnCodeToFieldCode
	 * @return array<int, array<string, string>>
	 */
	private function splitIntoUniqueFieldBatches(array $columnCodeToFieldCode): array
	{
		$batches = [];
		foreach ($columnCodeToFieldCode as $columnCode => $fieldCode)
		{
			foreach ($batches as $index => $batch)
			{
				if (!in_array($fieldCode, $batch, true))
				{
					$batches[$index][$columnCode] = $fieldCode;

					continue 2;
				}
			}

			$batches[] = [$columnCode => $fieldCode];
		}

		return $batches;
	}

	/** @return array<string, SourceRef> */
	private function indexSourceRefsByAlias(array $definition): array
	{
		$result = [];
		foreach ((array)($definition['sources'] ?? []) as $source)
		{
			$source = (array)$source;
			$alias = (string)($source['alias'] ?? '');
			if ($alias !== '')
			{
				$result[$alias] = SourceRef::fromArray($source);
			}
		}

		return $result;
	}

	/**
	 * Result codes of COUNT aggregate functions: a COUNT changes the value's meaning to a quantity, so
	 * it must not be presented as if it were a value of the field it counted over.
	 *
	 * @return array<string, true>
	 */
	private function countFunctionCodes(array $definition): array
	{
		if ((string)($definition['operation'] ?? '') !== 'aggregate')
		{
			return [];
		}

		$countCodes = [];
		foreach ((array)($definition['aggregate']['functions'] ?? []) as $function)
		{
			$function = (array)$function;
			$code = (string)($function['code'] ?? '');
			if ($code !== '' && strtoupper((string)($function['fn'] ?? '')) === 'COUNT')
			{
				$countCodes[$code] = true;
			}
		}

		return $countCodes;
	}

	/**
	 * Columns of one source, presented by that source. A source unavailable by now leaves its own
	 * columns raw instead of dropping the overlay built for the other sources.
	 *
	 * @param array<int|string, array<string, mixed>> $rows
	 * @param array<string, string> $columnCodeToFieldCode
	 * @param array<int|string, array<string, string>> $overlay
	 */
	private function applyProviderOverlay(
		SourceRef $sourceRef,
		array $rows,
		array $columnCodeToFieldCode,
		array &$overlay,
		int $actorId,
	): void
	{
		$provider = $this->registry->get($sourceRef->module);
		if (!$provider instanceof PresentableProvider)
		{
			return;
		}

		foreach ($this->splitIntoUniqueFieldBatches($columnCodeToFieldCode) as $batch)
		{
			$fieldRows = [];
			foreach ($rows as $rowKey => $row)
			{
				$fieldRow = [];
				foreach ($batch as $columnCode => $fieldCode)
				{
					if (array_key_exists($columnCode, $row))
					{
						$fieldRow[$fieldCode] = $row[$columnCode];
					}
				}
				$fieldRows[$rowKey] = $fieldRow;
			}

			try
			{
				// The actor rides as a trailing argument the two-parameter contract does not declare:
				// a provider from a module on its own release cycle must keep loading against this
				// bizproc, so the wider signature stays out of {@see PresentableProvider}.
				$presentedRows = $provider->present($sourceRef, $fieldRows, $actorId);
			}
			catch (\Throwable)
			{
				continue;
			}

			foreach ($presentedRows as $rowKey => $fieldLabels)
			{
				foreach ($batch as $columnCode => $fieldCode)
				{
					if (is_array($fieldLabels) && array_key_exists($fieldCode, $fieldLabels))
					{
						$overlay[$rowKey][$columnCode] = (string)$fieldLabels[$fieldCode];
					}
				}
			}
		}
	}
}

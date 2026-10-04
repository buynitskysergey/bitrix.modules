<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Builder;

use Bitrix\Main\DB\Ddl\Column\ColumnInterface;
use Bitrix\Main\DB\Ddl\Column\ColumnState;
use Bitrix\Main\DB\Ddl\IndexColumn;

class AlterTableBuilder
{
	use IndexesTrait;

	/** @var array<string, IndexColumn[]> Index name hint → optional columns used to resolve the real name. */
	private array $dropIndexHints = [];
	private bool $dropPrimaryKey = false;

	/** @var string[] List of column names to drop. */
	private array $droppedColumns = [];
	/** @var array<string, string> Map of oldName → newName. */
	private array $renamedColumns = [];

	private ?AddColumnBuilder $addColumnBuilder = null;
	private ?ModifyColumnBuilder $modifyColumnBuilder = null;
	/** @var array<string, ColumnState> */
	private array $columnStates = [];

	/**
	 * @param string $tableName
	 * @param ?\Closure $columnFilter `fn(ColumnInterface $col): bool`. Return false to skip the column
	 *    from ADD COLUMN rendering.
	 * @param ?\Closure $addedIndexFilter `fn(string $name, array{type: string, columns: IndexColumn[]} $info): bool`.
	 *    Return false to skip the index.
	 * @param ?\Closure $currentPrimaryKeyResolver `fn(): string[]`. Returns the columns of the
	 *    table's current primary key. When `dropPrimaryKey()` is combined with `addPrimaryKey/s(...)`
	 * @param ?\Closure $dropIndexNameResolver `fn(string[] $columnNames, string $hintName): ?string`.
	 *    Resolves the live index name for the given columns, or null to skip the drop operation.
	 * @param ?\Closure $modifiedColumnFilter `fn(ColumnInterface $col): bool`. Return false to skip the column
	 *    from MODIFY COLUMN rendering.
	 * @param ?\Closure $columnStateFactory `fn(ColumnInterface[] $columns): array<string, ColumnState>`.
	 */
	public function __construct(
		protected readonly string $tableName,
		private readonly ?\Closure $columnFilter = null,
		private readonly ?\Closure $addedIndexFilter = null,
		private readonly ?\Closure $currentPrimaryKeyResolver = null,
		private readonly ?\Closure $dropIndexNameResolver = null,
		private readonly ?\Closure $modifiedColumnFilter = null,
		private readonly ?\Closure $columnStateFactory = null,
	)
	{
	}

	public function addColumn(): AddColumnBuilder
	{
		if (!$this->addColumnBuilder)
		{
			$this->addColumnBuilder = new AddColumnBuilder($this->tableName);
		}

		return $this->addColumnBuilder;
	}

	public function modifyColumn(): ModifyColumnBuilder
	{
		if (!$this->modifyColumnBuilder)
		{
			$this->modifyColumnBuilder = new ModifyColumnBuilder($this->tableName);
		}

		return $this->modifyColumnBuilder;
	}

	public function dropIndex(string $name, array $columns = []): static
	{
		$this->dropIndexHints[$name] = IndexColumn::normalizeList($columns);

		return $this;
	}

	public function dropPrimaryKey(): static
	{
		$this->dropPrimaryKey = true;

		return $this;
	}

	/**
	 * Shortcut: adds an `ID` column (auto-incrementing INT, NOT NULL) and registers
	 * it as the table's primary key.
	 */
	public function addId(): void
	{
		$this->addColumn()->int('ID')->notNull()->autoincrement();
		$this->addPrimaryKey('ID');
	}

	public function dropColumn(string $columnName): static
	{
		$this->droppedColumns[] = $columnName;

		return $this;
	}

	public function renameColumn(string $oldName, string $newName): static
	{
		$this->renamedColumns[$oldName] = $newName;

		return $this;
	}

	/**
	 * @param ColumnInterface[] $columns
	 * @return ColumnInterface[]
	 */
	private function filterColumns(array $columns, ?\Closure $filter): array
	{
		if ($filter === null)
		{
			return $columns;
		}

		return array_filter($columns, static fn(ColumnInterface $col): bool => $filter($col));
	}

	/**
	 * @internal
	 */
	public function toData(): AlterTableData
	{
		[$dropPrimaryKey, $primaryKeys] = $this->resolvePrimaryKeyState();
		$modifiedColumns = $this->filterColumns(
			$this->modifyColumnBuilder?->getColumns() ?? [],
			$this->modifiedColumnFilter,
		);
		$this->columnStates = [];
		if ($this->columnStateFactory !== null && !empty($modifiedColumns))
		{
			$this->columnStates = ($this->columnStateFactory)($modifiedColumns);
		}

		return $this->createData(
			tableName: $this->tableName,
			addedColumns: $this->filterColumns($this->addColumnBuilder?->getColumns() ?? [], $this->columnFilter),
			modifiedColumns: $modifiedColumns,
			dropIndexNames: $this->collectDropIndexNames(),
			dropPrimaryKey: $dropPrimaryKey,
			primaryKeys: $primaryKeys,
			addedIndexes: $this->filterAddedIndexes($this->addedIndexes),
			droppedColumns: $this->droppedColumns,
			renamedColumns: $this->renamedColumns,
		);
	}

	/** @return array<string, ColumnState> */
	protected function getColumnStatesForData(): array
	{
		return $this->columnStates;
	}

	protected function collectDropIndexNames(): array
	{
		$names = [];
		foreach ($this->dropIndexHints as $hintName => $columns)
		{
			if ($this->dropIndexNameResolver === null)
			{
				$names[] = $hintName;
				continue;
			}
			$columnNames = array_map(static fn(IndexColumn $col): string => $col->getName(), $columns);
			$resolvedName = ($this->dropIndexNameResolver)($columnNames, $hintName);
			if ($resolvedName !== null)
			{
				$names[] = $resolvedName;
			}
		}

		return $names;
	}

	protected function resolvePrimaryKeyState(): array
	{
		if ($this->currentPrimaryKeyResolver === null)
		{
			return [$this->dropPrimaryKey, $this->primaryKeys];
		}
		if (!$this->dropPrimaryKey && empty($this->primaryKeys))
		{
			return [false, []];
		}

		$current = ($this->currentPrimaryKeyResolver)();
		$matchesCurrent =
			!empty($this->primaryKeys)
			&& $this->normalizeColumnsForCompare($current)
				=== $this->normalizeColumnsForCompare($this->primaryKeys);

		$dropPrimaryKey = $this->dropPrimaryKey;
		if ($dropPrimaryKey && (empty($current) || $matchesCurrent))
		{
			$dropPrimaryKey = false;
		}

		$primaryKeys = $this->primaryKeys;
		if (!empty($primaryKeys) && $matchesCurrent)
		{
			$primaryKeys = [];
		}

		return [$dropPrimaryKey, $primaryKeys];
	}

	/**
	 * @param array<string, array{type: string, columns: IndexColumn[]}> $indexes
	 * @return array<string, array{type: string, columns: IndexColumn[]}>
	 */
	protected function filterAddedIndexes(array $indexes): array
	{
		if ($this->addedIndexFilter === null)
		{
			return $indexes;
		}
		$filter = $this->addedIndexFilter;
		$result = [];
		foreach ($indexes as $name => $info)
		{
			if ($filter($name, $info))
			{
				$result[$name] = $info;
			}
		}

		return $result;
	}

	/**
	 * @param string[] $cols
	 * @return string[]
	 */
	private function normalizeColumnsForCompare(array $cols): array
	{
		return array_map(static fn(string $col): string => strtolower($col), $cols);
	}

	/**
	 *
	 * @param ColumnInterface[] $addedColumns
	 * @param ColumnInterface[] $modifiedColumns
	 * @param string[] $dropIndexNames
	 * @param string[] $primaryKeys
	 * @param array<string, array{type: string, columns: IndexColumn[]}> $addedIndexes
	 * @param string[] $droppedColumns
	 * @param array<string, string> $renamedColumns
	 */
	protected function createData(
		string $tableName,
		array $addedColumns,
		array $modifiedColumns,
		array $dropIndexNames,
		bool $dropPrimaryKey,
		array $primaryKeys,
		array $addedIndexes,
		array $droppedColumns,
		array $renamedColumns,
	): AlterTableData
	{
		return new AlterTableData(
			tableName: $tableName,
			addedColumns: $addedColumns,
			modifiedColumns: $modifiedColumns,
			dropIndexNames: $dropIndexNames,
			dropPrimaryKey: $dropPrimaryKey,
			primaryKeys: $primaryKeys,
			addedIndexes: $addedIndexes,
			droppedColumns: $droppedColumns,
			renamedColumns: $renamedColumns,
			columnStates: $this->getColumnStatesForData(),
		);
	}
}

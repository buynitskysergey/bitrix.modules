<?php

namespace Bitrix\Main\UpdateSystem\Migration;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\Ddl\Column\AbstractIntColumn;
use Bitrix\Main\DB\Ddl\Column\ColumnInterface;
use Bitrix\Main\DB\Ddl\Column\ColumnState;
use Bitrix\Main\DB\Ddl\Column\ColumnStateData;
use Bitrix\Main\DB\Ddl\Column\TimestampColumn;
use Bitrix\Main\DB\Ddl\Exception as DdlException;
use Bitrix\Main\DB\Ddl\IndexColumn;
use Bitrix\Main\DB\Ddl\Renderer\RendererFactory;
use Bitrix\Main\UpdateSystem\Migration\Tools\Database;

class Table
{
	private ?string $changeTableType = null;
	private readonly IndexNameProcessor $indexNameProcessor;

	public function __construct(
		private readonly string $tableName,
		private readonly Context $context,
		?IndexNameProcessor $indexNameProcessor = null,
	)
	{
		$this->indexNameProcessor = $indexNameProcessor ?? new IndexNameProcessor($context);
	}

	/**
	 * @param \Closure(\Bitrix\Main\UpdateSystem\Migration\CreateTableBuilder $table): void $callback
	 * @return self
	 * @throws Exception
	 */
	public function create(\Closure $callback): self
	{
		return $this->withMasterOnly(function () use ($callback): self {
			if ($this->context->getDatabaseUpdateMode() === DatabaseUpdateMode::ModuleUninstall)
			{
				$this->drop();

				return $this;
			}

			$this->checkIfTableAlreadyChanged('create');

			if (!$this->context->getDatabaseUpdateMode()->executesDdl())
			{
				return $this;
			}

			$builder = $this->createBuilder();
			$callback($builder);

			$this->checkNoTimestampColumns($builder->addColumn()->getColumns());

			$data = $builder->toData();

			$mode = $this->context->getDatabaseUpdateMode();
			if ($data->isPreliminaryExecutionDisabled() && $mode->isPreliminary())
			{
				return $this;
			}

			// During module install the module's tables do not exist yet — that's the
			// whole point of running tables.php. Skip the moduleTablesExist gate.
			if ($mode->isModuleInstall() || $this->context->moduleTablesExist($this->tableName))
			{
				$queries = RendererFactory::get($this->context->getDbType())->renderCreateTable($data);
				foreach ($queries as $query)
				{
					$this->executeQuery($query);
				}
			}

			return $this;
		});
	}

	/**
	 * @param \Closure(\Bitrix\Main\UpdateSystem\Migration\AlterTableBuilder $table): void $callback
	 * @return self
	 * @throws Exception
	 */
	public function alter(\Closure $callback): self
	{
		return $this->withMasterOnly(function () use ($callback): self {
			$this->checkIfTableAlreadyChanged('alter');

			$mode = $this->context->getDatabaseUpdateMode();
			// Module install only creates tables (nothing to alter yet); module uninstall
			// only drops tables (no auto-reverse for column/index changes). Both skip alter.
			if (!$mode->executesDdl() || $mode->isModuleInstall() || $mode === DatabaseUpdateMode::ModuleUninstall)
			{
				return $this;
			}

			$tableExists = null;
			$tableExistsResolver = function () use (&$tableExists): bool {
				$tableExists ??= $this->context->tableExists($this->tableName);

				return $tableExists;
			};

			$builder = $this->alterBuilder($tableExistsResolver);
			$callback($builder);

			$this->checkNoTimestampColumns(array_merge(
				$builder->addColumn()->getColumns(),
				$builder->modifyColumn()->getColumns(),
			));

			$data = $builder->toData();

			if ($data->isPreliminaryExecutionDisabled() && $mode->isPreliminary())
			{
				return $this;
			}

			if ($mode->usesRealDatabase() && !$tableExistsResolver())
			{
				return $this;
			}

			$queries = RendererFactory::get($this->context->getDbType())->renderAlterTable($data);
			foreach ($queries as $query)
			{
				$this->executeQuery($query);
			}

			return $this;
		});
	}

	/**
	 * @param ?\Closure(\Bitrix\Main\UpdateSystem\Migration\DropTableBuilder $table): void $callback
	 *    Optional — pass a callback only when you need to tweak the drop (e.g. {@see DropTableBuilder::disablePreliminaryExecution()}).
	 * @return self
	 * @throws Exception
	 */
	public function drop(?\Closure $callback = null): self
	{
		return $this->withMasterOnly(function () use ($callback): self {
			$this->checkIfTableAlreadyChanged('drop');

			$mode = $this->context->getDatabaseUpdateMode();
			// Module install only creates tables — drop is skipped even though the mode
			// otherwise allows forward writes.
			if ($mode->isModuleInstall())
			{
				return $this;
			}

			$builder = new DropTableBuilder($this->tableName);
			if ($callback !== null)
			{
				$callback($builder);
			}

			$data = $builder->toData();

			if ($data->isPreliminaryExecutionDisabled() && $mode->isPreliminary())
			{
				return $this;
			}

			if ($mode->canUpdateDatabase() || $mode === DatabaseUpdateMode::ModuleUninstall)
			{
				$sql = RendererFactory::get($this->context->getDbType())->renderDropTable($this->tableName);
				$this->executeQuery($sql);
			}

			return $this;
		});
	}

	/**
	 * Inserts a row with data `$fieldsValues` into the table. The row will be inserted only if `$conditionsCallback` returns true.
	 * @param array $fieldsValues
	 * @param \Closure(\Bitrix\Main\UpdateSystem\Migration\Context $context): ?string $conditionsCallback
	 * @return self
	 */
	public function insertRow(
		array $fieldsValues,
		\Closure $conditionsCallback,
	): self
	{
		return $this->withMasterOnly(function () use ($fieldsValues, $conditionsCallback): self {
			if (
				$this->context->getDatabaseUpdateMode()->canUpdateDatabase()
				&& $this->context->tableExists($this->tableName)
				&& !empty($fieldsValues)
				&& $conditionsCallback())
			{
				$sqlHelper = $this->context->getConnection()->getSqlHelper();
				$insert = $sqlHelper->prepareInsert($this->tableName, $fieldsValues);
				$sql = 'INSERT INTO ' . $sqlHelper->quote($this->tableName) . '(' . $insert[0] . ') ' . 'VALUES (' . $insert[1] . ')';

				$this->executeQuery($sql);
			}

			return $this;
		});
	}

	/**
	 * Bulk-inserts multiple rows in a single `INSERT INTO … VALUES (…), (…), …` statement.
	 * All rows must share the same set of columns. The whole batch is wrapped by `$conditionsCallback`
	 *
	 * @param array<int, array<string, mixed>> $rows List of rows. Each row is a `'COLUMN' => value` map.
	 * @param \Closure(): bool $conditionsCallback Returns true when the batch should be inserted.
	 * @return self
	 * @throws Exception If `$rows` contain mismatched column sets (code 1020).
	 */
	public function insertRows(
		array $rows,
		\Closure $conditionsCallback,
	): self
	{
		return $this->withMasterOnly(function () use ($rows, $conditionsCallback): self {
			if (
				!$this->context->getDatabaseUpdateMode()->canUpdateDatabase()
				|| !$this->context->tableExists($this->tableName)
				|| empty($rows)
				|| !$conditionsCallback()
			)
			{
				return $this;
			}

			$rows = array_values(array_filter($rows, static fn(array $row): bool => !empty($row)));
			if (empty($rows))
			{
				return $this;
			}

			$sqlHelper = $this->context->getConnection()->getSqlHelper();
			$columnsList = null;
			$valueGroups = [];
			foreach ($rows as $row)
			{
				$insert = $sqlHelper->prepareInsert($this->tableName, $row);
				if ($columnsList === null)
				{
					$columnsList = $insert[0];
				}
				elseif ($columnsList !== $insert[0])
				{
					throw new Exception(
						$this->context->getModuleId(),
						1020,
						'All rows in insertRows() must share the same column set',
						['table' => $this->tableName],
					);
				}
				$valueGroups[] = '(' . $insert[1] . ')';
			}

			$sql = 'INSERT INTO ' . $sqlHelper->quote($this->tableName)
				. ' (' . $columnsList . ') VALUES '
				. implode(', ', $valueGroups);

			$this->executeQuery($sql);

			return $this;
		});
	}

	/**
	 * @param \Closure(\Bitrix\Main\UpdateSystem\Migration\Context $context): ?string $sqlQueryCallback
	 * @return self
	 */
	public function query(
		\Closure $sqlQueryCallback,
	): self
	{
		return $this->withMasterOnly(function () use ($sqlQueryCallback): self {
			if ($this->context->getDatabaseUpdateMode()->canUpdateDatabase() && $this->context->tableExists($this->tableName))
			{
				$sql = $sqlQueryCallback();
				if (!empty($sql))
				{
					$this->executeQuery($sql);
				}
			}

			return $this;
		});
	}

	/**
	 * Runs the operation in the master-only scope so every query (schema
	 * introspection and DDL/DML alike) stays on the migration connection.
	 * Without it a SELECT/SHOW issued through an overridden connection would be
	 * routed to the default portal slave on a cluster, ignoring the override.
	 *
	 * @param \Closure(): self $operation
	 * @return self
	 */
	private function withMasterOnly(\Closure $operation): self
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			return $operation();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private function createBuilder(): CreateTableBuilder
	{
		$context = $this->context;
		$tableName = $this->tableName;

		return new CreateTableBuilder(
			tableName: $tableName,
			tableExistsFilter: static fn(): bool => $context->getDatabaseUpdateMode()->usesRealDatabase()
				&& $context->tableExists($tableName),
			addedIndexFilter: static function(string $_indexName, array $info) use ($context, $tableName): bool {
				if (!$context->getDatabaseUpdateMode()->usesRealDatabase())
				{
					return true;
				}
				if (!$context->tableExists($tableName))
				{
					return true;
				}
				$columnNames = array_map(
					static fn(IndexColumn $col): string => strtolower($col->getName()),
					$info['columns'],
				);

				return !$context->indexExists($tableName, $columnNames);
			},
			indexNameProcessor: $this->indexNameProcessor,
		);
	}

	private function alterBuilder(\Closure $tableExistsResolver): AlterTableBuilder
	{
		$context = $this->context;
		$tableName = $this->tableName;
		$usesRealDatabase = $context->getDatabaseUpdateMode()->usesRealDatabase();

		return new AlterTableBuilder(
			tableName: $tableName,
			columnFilter: static function(ColumnInterface $col) use (
				$context,
				$tableName,
				$tableExistsResolver,
			): bool {
				if (!$context->getDatabaseUpdateMode()->usesRealDatabase())
				{
					return true;
				}
				if (!$tableExistsResolver())
				{
					return true;
				}

				return !$context->columnExists($tableName, $col->getParams()->getName());
			},
			addedIndexFilter: static function(string $_indexName, array $info) use (
				$context,
				$tableName,
				$tableExistsResolver,
			): bool {
				if (!$context->getDatabaseUpdateMode()->usesRealDatabase())
				{
					return true;
				}
				if (!$tableExistsResolver())
				{
					return true;
				}
				$columnNames = array_map(static fn(IndexColumn $col): string => $col->getName(), $info['columns']);
				if ($context->isPostgreSql())
				{
					$columnNames = array_map(strtolower(...), $columnNames);
				}

				return $context->getIndexName($tableName, $columnNames) === null;
			},
			currentPrimaryKeyResolver: static function() use (
				$context,
				$tableName,
				$tableExistsResolver,
			): array {
				if (!$context->getDatabaseUpdateMode()->usesRealDatabase())
				{
					return [];
				}
				if (!$tableExistsResolver())
				{
					return [];
				}

				return $context->getPrimaryKeyColumns($tableName);
			},
			dropIndexNameResolver: !$usesRealDatabase ? null : function(
				array $columnNames,
				string $hintName,
			) use ($context, $tableName, $tableExistsResolver): ?string {
				if (empty($columnNames))
				{
					if ($context->isDevMode())
					{
						throw new Exception(
							$context->getModuleId(),
							1104,
							'Index "' . $hintName . '" in table "' . $tableName
								. '" cannot be dropped safely: pass the index columns to dropIndex()',
							[
								'table' => $tableName,
								'index' => $hintName,
							],
						);
					}

					$this->logSkippedOperation('dropIndex', $hintName, 'columns are required');

					return null;
				}
				if (!$tableExistsResolver())
				{
					return $hintName;
				}

				$indexName = $context->getIndexName($tableName, $columnNames);
				if ($indexName === null)
				{
					$this->logSkippedOperation('dropIndex', $hintName, 'missing index');
				}

				return $indexName;
			},
			modifiedColumnFilter: !$usesRealDatabase ? null : function(
				ColumnInterface $column,
			) use ($context, $tableName, $tableExistsResolver): bool {
				if (!$tableExistsResolver())
				{
					return false;
				}

				$columnName = $column->getParams()->getName();
				if (!$context->columnExists($tableName, $columnName))
				{
					$this->logSkippedOperation('modifyColumn', $columnName, 'missing column');

					return false;
				}

				return true;
			},
			columnStateFactory: !$usesRealDatabase || !$context->isPostgreSql()
				? null
				: function(array $columns) use ($tableName): array {
					$candidateNames = [];
					foreach ($columns as $column)
					{
						if ($column instanceof AbstractIntColumn && $column->getParams()->isAutoincrement())
						{
							$candidateNames[] = strtolower($column->getParams()->getName());
						}
					}
					$candidateNames = array_values(array_unique($candidateNames));
					if (empty($candidateNames))
					{
						return [];
					}

					$resolvedStates = null;
					$loader = function () use (&$resolvedStates, $candidateNames): array {
						$resolvedStates ??= $this->resolvePostgreSqlColumnStates($candidateNames);

						return $resolvedStates;
					};

					$states = [];
					foreach ($candidateNames as $columnName)
					{
						$states[$columnName] = new ColumnState(
							$columnName,
							function () use ($loader, $tableName, $columnName): ?ColumnStateData {
								$resolvedStates = $loader();
								if (!isset($resolvedStates[$columnName]))
								{
									if ($this->context->isDevMode())
									{
										throw new DdlException(
											1106,
											'Column state is incomplete',
											[
												'table' => $tableName,
												'column' => $columnName,
												'reason' => 'column state row is missing',
											],
										);
									}

									$this->logSkippedOperation(
										'modifyColumn',
										$columnName,
										'missing column state',
									);

									return null;
								}

								return $resolvedStates[$columnName];
							},
						);
					}

					return $states;
				},
			indexNameProcessor: $this->indexNameProcessor,
		);
	}

	/**
	 * @param string[] $columnNames
	 * @return array<string, ColumnStateData>
	 */
	protected function resolvePostgreSqlColumnStates(array $columnNames): array
	{
		return Database::getPostgreSqlColumnStates(
			$this->tableName,
			$columnNames,
			$this->context->getConnection(),
		);
	}

	private function logSkippedOperation(string $operation, string $object, string $reason): void
	{
		$this->writeLog(
			"Skip updater '" . $this->context->getUpdaterFilename() . "': table=" . $this->tableName
				. '; operation=' . $operation
				. '; object=' . $object
				. '; reason=' . $reason,
		);
	}

	/**
	 * Timestamp columns are deprecated: PostgreSQL does not support MySQL TIMESTAMP
	 * semantics. The check is declaration-based and fires only for the module's open
	 * "current" updater, so already released updaters keep working as is.
	 *
	 * @param ColumnInterface[] $columns
	 * @throws Exception
	 */
	private function checkNoTimestampColumns(array $columns): void
	{
		if (!$this->context->isCurrentDevUpdater())
		{
			return;
		}

		foreach ($columns as $column)
		{
			if ($column instanceof TimestampColumn)
			{
				$columnName = $column->getParams()->getName();

				throw new Exception(
					$this->context->getModuleId(),
					1103,
					'Column "' . $columnName . '" in table "' . $this->tableName
						. '" uses the deprecated timestamp type (not supported by PostgreSQL), use datetime instead',
					[
						'table' => $this->tableName,
						'column' => $columnName,
					],
				);
			}
		}
	}

	private function checkIfTableAlreadyChanged(string $changeTableType): void
	{
		if (!is_null($this->changeTableType) && $this->context->isDevMode())
		{
			throw new Exception(
				$this->context->getModuleId(),
				1101,
				'Table "' . $this->tableName . '" was already modified in this migration',
				[
					'table' => $this->tableName,
				],
			);
		}
		$this->changeTableType = $changeTableType;
	}

	protected function executeQuery(string $sql): void
	{
		if (!$this->context->getDatabaseUpdateMode()->usesRealDatabase())
		{
			return;
		}

		$connection = $this->context->getConnection();

		$mode = $this->context->getDatabaseUpdateMode();
		$isModuleInstallOrUninstall =
			$mode === DatabaseUpdateMode::ModuleInstall
			|| $mode === DatabaseUpdateMode::ModuleUninstall;

		if (!$isModuleInstallOrUninstall)
		{
			$logMessage = "Run updater '" . $this->context->getUpdaterFilename() . "': Query(" . $sql . ', ' . $this->tableName . ')';
			if (!empty($connection->getNodeId()))
			{
				$logMessage .= ' [connection: ' . $this->getConnectionId($connection) . ']';
			}
			$this->writeLog($logMessage);
		}

		try
		{
			$connection->queryExecute($sql);
		}
		catch (\Bitrix\Main\DB\Exception $e)
		{
			$error = $e->getDatabaseMessage();
			if (!empty($connection->getNodeId()))
			{
				$error .= ' [connection: ' . $this->getConnectionId($connection) . ']';
			}

			// In dev mode any failure is loud (Exception 1199). Otherwise the updater flow
			// collects the error in CUpdater, while module install/uninstall must surface it
			// in the Result returned by installMigrations()/uninstallMigrations() - so it throws.
			if (!$isModuleInstallOrUninstall && !$this->context->isDevMode() && class_exists(\CUpdater::class))
			{
				\CUpdater::addError($error);
			}
			else
			{
				throw new Exception(
					$this->context->getModuleId(),
					1199,
					'Wrong sql query in ' . $this->tableName . ' table: ' . $sql . '. ' . $error,
					[
						'table' => $this->tableName,
						'sql' => $sql,
						'error' => $error,
					],
				);
			}
		}
	}

	protected function writeLog(string $message): void
	{
		\CUpdateClient::AddMessage2Log($message, 'CRUPDCDF2');
	}

	private function getConnectionId(Connection $connection): string
	{
		return 'node #' . $connection->getNodeId();
	}
}

<?php

namespace Bitrix\BIConnector\DataSourceConnector\Connector;

use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\BIConnector\Aggregate;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\DataSourceConnector\Aggregation;
use Bitrix\BIConnector\DB\BiAggregationDialectInterface;
use Bitrix\Main\DB;

class Sql extends Base
{
	protected const ANALYTIC_TAG_DATASET = 'system';

	private const ABORT_CHECK_DB_ROW_INTERVAL = 500;

	protected ?string $biQueryError = null;

	/**
	 * Aggregation is delegated only when the connection is available and its helper implements the
	 * aggregation contract; the dataset gates of design §6.5 are evaluated inside Capabilities.
	 */
	public function getAggregationCapabilities(): Aggregation\Capabilities
	{
		if (!Feature::isAggregationPushdownEnabled())
		{
			return Aggregation\Capabilities::unsupported();
		}

		return Aggregation\Capabilities::build(
			$this->getAggregationDialect(),
			$this->getDatasetSchema(),
			$this->getAggregationScopeId(),
		);
	}

	protected function getAggregationDialect(): ?BiAggregationDialectInterface
	{
		try
		{
			$helper = $this->getConnection()?->getSqlHelper();
		}
		catch (\Throwable)
		{
			return null;
		}

		return $helper instanceof BiAggregationDialectInterface ? $helper : null;
	}

	/**
	 * @return array{readRows: int, readBytes: int}|null
	 */
	public function getLastScanStatistics(): ?array
	{
		try
		{
			$connection = $this->getConnection();
		}
		catch (\Throwable)
		{
			return null;
		}

		// Only a source that reports the scanned volume can answer; the others stay silent.
		if ($connection === null || !method_exists($connection, 'getLastQueryStatistics'))
		{
			return null;
		}

		return $connection->getLastQueryStatistics();
	}

	/**
	 * @return DB\Result|bool
	 * @throws ConfigurationException
	 */
	protected function biQuery(string $sql): DB\Result|bool
	{
		$connection = $this->getConnection();
		if (!$connection)
		{
			return false;
		}

		return $this->executeBiQuery($connection, $sql);
	}

	protected function executeBiQuery(DB\Connection $connection, string $sql): DB\Result|bool
	{
		$this->activeQueryConnection = $connection;
		$this->biQueryError = null;

		if (!$this->requiresPinnedExecutorConnection())
		{
			return $connection->biQuery($sql);
		}

		$pool = \Bitrix\Main\Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			if (!$this->applySqlTimeLimit($connection))
			{
				$this->biQueryError = 'Failed to enforce SQL time limit';

				return false;
			}

			return $connection->biQuery($sql);
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	/**
	 * @throws ConfigurationException
	 */
	protected function getQueryErrorMessage(): string
	{
		if ($this->biQueryError !== null)
		{
			return $this->biQueryError;
		}

		return ($this->activeQueryConnection ?? $this->getConnection())?->getErrorMessage() ?? '';
	}

	/**
	 * @param array $parameters
	 * @param int $limit
	 * @param array $dateFormats
	 *
	 * @return \Generator
	 *
	 * @throws ConfigurationException
	 */
	public function query(
		array $parameters,
		int $limit,
		array $dateFormats = [],
	): \Generator
	{
		$result = new Result();
		$data = $this->getFormattedData($parameters, $dateFormats);
		if (isset($data['error']))
		{
			$result->addError(new Error($data['error']));

			return $result;
		}

		$resultQuery = $this->biQuery($data['sql']);
		if (!$resultQuery)
		{
			$result->addError(new Error('SQL_ERROR', 0, ['description' => $this->getQueryErrorMessage()]));

			return $result;
		}

		$schema = $data['schema'] ?? [];
		$shadowFields = $data['shadowFields'] ?? [];
		$extraCount = count($shadowFields);
		$selectFields = array_merge(array_values($schema) , array_values($shadowFields));

		$primary = [];
		foreach ($selectFields as $i => $fieldInfo)
		{
			if (isset($fieldInfo['IS_PRIMARY']) && $fieldInfo['IS_PRIMARY'] === 'Y')
			{
				$primary[] = $i;
			}
		}

		$groupFields = [];
		foreach ($selectFields as $i => $fieldInfo)
		{
			if (isset($fieldInfo['GROUP_CONCAT']))
			{
				foreach ($selectFields as $j => $keyInfo)
				{
					if ($keyInfo['ID'] === $fieldInfo['GROUP_KEY'])
					{
						if ($fieldInfo['TYPE'] === 'ARRAY_STRING')
						{
							$delimiter = null;
							if ($fieldInfo['IS_VALUE_SPLITABLE'] ?? false)
							{
								$delimiter = $fieldInfo['GROUP_CONCAT'];
							}

							$groupFields[$i] = [
								'unique_id' => $j,
								'state' => new Aggregate\ArrayStringState($delimiter),
							];
						}
						else
						{
							$groupFields[$i] = [
								'unique_id' => $j,
								'state' => new Aggregate\ConcatState($fieldInfo['GROUP_CONCAT']),
							];
						}

						break;
					}
				}
			}
			elseif (isset($fieldInfo['GROUP_COUNT']))
			{
				foreach ($selectFields as $j => $keyInfo)
				{
					if ($keyInfo['ID'] === $fieldInfo['GROUP_KEY'])
					{
						$groupFields[$i] = [
							'unique_id' => $j,
							'state' => new Aggregate\CountState($fieldInfo['GROUP_COUNT'] === 'DISTINCT'),
						];

						break;
					}
				}
			}
		}

		$prevPrimaryKey = '';
		$outputRow = null;
		$rowsCount = 0;
		$rowsSinceAbortCheck = 0;

		// A server may fail after the rows started flowing - ClickHouse answers 200, streams data and
		// appends the error to the body - and such a failure surfaces as an exception from fetch().
		// Caught here and turned into the same Result the early failures return, so the endpoint can
		// finish its buffers and report the aggregation as not applied instead of dying mid-stream.
		try
		{
			while ($row = $resultQuery->fetch())
			{
				if (++$rowsSinceAbortCheck >= self::ABORT_CHECK_DB_ROW_INTERVAL)
				{
					$rowsSinceAbortCheck = 0;
					if ($this->shouldAbort())
					{
						$this->abortActiveQuery();

						return $result;
					}
				}

				if ($limit && $rowsCount === $limit)
				{
					/** It`s total prohibited for changing `continue` to `break` for avoiding `Commands out of sync`. The issue is 0205035 */
					continue;
				}

				$onAfterCallbacks = $data['onAfterFetch'] ?? [];
				foreach ($onAfterCallbacks as $i => $callback)
				{
					$row[$i] = $callback($row[$i], $dateFormats);
				}

				$primaryKey = '';
				foreach ($primary as $primaryIndex)
				{
					if ($primaryKey)
					{
						$primaryKey .= '-';
					}
					$primaryKey .= $row[$primaryIndex];
				}

				if ($primary && $groupFields)
				{
					if ($outputRow === null)
					{
						$outputRow = $row;
						foreach ($groupFields as $i => $groupInfo)
						{
							$groupId = $row[$groupInfo['unique_id']];
							$outputRow[$i] = clone $groupInfo['state'];
							$outputRow[$i]->updateState($groupId, $row[$i]);
						}
					}
					elseif ($primaryKey === $prevPrimaryKey)
					{
						foreach ($groupFields as $i => $groupInfo)
						{
							$groupId = $row[$groupInfo['unique_id']];
							$outputRow[$i]->updateState($groupId, $row[$i]);
						}
					}
					else
					{
						foreach ($groupFields as $i => $groupInfo)
						{
							$outputRow[$i] = $outputRow[$i]->output();
						}
						if ($extraCount)
						{
							array_splice($outputRow, -$extraCount);
						}

						yield $outputRow;
						$rowsCount++;

						$outputRow = $row;
						foreach ($groupFields as $i => $groupInfo)
						{
							$groupId = $row[$groupInfo['unique_id']];
							$outputRow[$i] = clone $groupInfo['state'];
							$outputRow[$i]->updateState($groupId, $row[$i]);
						}
					}
					$prevPrimaryKey = $primaryKey;
				}
				else
				{
					if ($extraCount)
					{
						array_splice($row, -$extraCount);
					}

					yield $row;
					$rowsCount++;
				}
			}

			if ($outputRow && !($limit && $rowsCount === $limit))
			{
				foreach ($groupFields as $i => $groupInfo)
				{
					$outputRow[$i] = $outputRow[$i]->output();
				}

				if ($extraCount)
				{
					array_splice($outputRow, -$extraCount);
				}

				yield $outputRow;
			}
		}
		catch (DB\SqlQueryException $exception)
		{
			$result->addError(
				new Error(
					'SQL_ERROR',
					0,
					['description' => $exception->getDatabaseMessage() ?: $exception->getMessage()],
				),
			);
		}
		catch (\Exception $exception)
		{
			\AddMessage2Log(
				'biconnector: unexpected failure while reading the query result of dataset "'
				. $this->name . '": ' . $exception,
				'biconnector',
			);

			$result->addError(new Error('SQL_ERROR'));
		}
		finally
		{
			$this->restoreSqlTimeLimit();
		}

		return $result;
	}

	/**
	 * @param \CBIConnectorSqlBuilder $builder
	 * @param array $tableInfo
	 * @param array|null $where
	 *
	 * @return string
	 */
	protected function getPrintedQuery(
		\CBIConnectorSqlBuilder $builder,
		array $tableInfo,
		?array $where = null,
		?array $queryParameters = null
	): string
	{
		$additionalJoins = $builder->GetJoins();

		$queryWhere = null;
		$whereJoints = null;
		if ($where)
		{
			$queryWhere = $builder->GetQuery($where);
			$whereJoints = $builder->GetJoins();
		}

		$limit = null;
		$limitSubQuery = null;
		if (
			isset($queryParameters['limit'])
			&& $queryParameters['limit'] > 0
			&& is_array($tableInfo['FIELDS'])
		)
		{
			$primaryKey = null;
			foreach ($tableInfo['FIELDS'] as $id => $fieldInfo)
			{
				if (($fieldInfo['IS_PRIMARY'] ?? null) === 'Y')
				{
					$primaryKey = $fieldInfo['FIELD_NAME'];
					$connection = $this->getConnection();
					if (!$connection)
					{
						break;
					}
					$helper = $connection->getSqlHelper();
					$primaryKey = $helper->forSql($primaryKey);

					break;
				}
			}

			$limit = "limit " . (int)$queryParameters['limit'];
			if (!empty($primaryKey))
			{
				$limit = " order by {$primaryKey} asc \n {$limit}";
			}

			if (!empty($additionalJoins))
			{
				if (!empty($primaryKey) && !empty($fieldInfo['FIELD_NAME']))
				{
					$subSelect = "	
						select $primaryKey as ID
						from
						{$tableInfo['TABLE_NAME']} AS {$tableInfo['TABLE_ALIAS']}
					";
					if ($queryWhere)
					{
						$subSelect .= $whereJoints ? "\n {$whereJoints}" : '';
						$subSelect .= $queryWhere ? "\n WHERE {$queryWhere}" : '';
					}

					$subSelect .= "\n {$limit} \n";
					$limitSubQuery = "INNER JOIN ({$subSelect}) as SUBQRYLIMIT on SUBQRYLIMIT.ID = {$primaryKey}";
				}
			}
		}

		$groupByClause = $this->buildGroupByClause($tableInfo['GROUP'] ?? null);

		return
			"select\n  " . $builder->GetSelect()
			. "\nfrom\n  " . $tableInfo['TABLE_NAME'] . ' AS ' . $tableInfo['TABLE_ALIAS']
			. ($limitSubQuery ? "\n  {$limitSubQuery}" : '')
			. ($additionalJoins ? "\n  " . $additionalJoins : '')
			. ($queryWhere ? "\nWHERE " . $queryWhere : '')
			. ($groupByClause ? "\nGROUP BY\n  " . $groupByClause : '')
			. (!empty($tableInfo['UNIONS']) ? "\n" . $this->getUnionQuery($builder, $tableInfo['UNIONS']) : '')
			. (empty($limitSubQuery) && !empty($limit) ? "\n {$limit}" : '')
			. "\n"
		;
	}

	private function getUnionQuery(\CBIConnectorSqlBuilder $builder, array $unions): string
	{
		$unionQuery = '';
		$selectFieldNames = $builder->GetSelectFieldNames();
		foreach ($unions as $union)
		{
			$formattedFields = [];
			$join = [];
			foreach ($union['FIELDS'] as $unionFieldKey => $unionField)
			{
				if (in_array($unionFieldKey, $selectFieldNames, true))
				{
					$formattedFields[] = $unionField['FIELD_NAME'] . ' AS ' . $unionFieldKey;
					if (!empty($unionField['LEFT_JOIN']))
					{
						$join[] = $unionField['LEFT_JOIN'];
					}
					elseif (!empty($unionField['JOIN']))
					{
						$join[] = $unionField['JOIN'];
					}
				}
			}
			$sqlFields = implode("\n  ,", $formattedFields);
			$unionQuery .= "UNION SELECT\n  " . $sqlFields
				. "\nfrom\n  " . $union['TABLE_NAME'] . ' AS ' . $union['TABLE_ALIAS']
				. "\n" . (!empty($join) ? implode("\n ", $join) : '')
				. "\n"
			;
		}

		return $unionQuery;
	}

	private function buildGroupByClause(?array $groupBy): string
	{
		if (empty($groupBy))
		{
			return '';
		}

		return implode("\n  ,", $groupBy);
	}
}

<?php

namespace Bitrix\Main\UpdateSystem\Migration\Tools;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\Ddl\Column\ColumnAutoincrementType;
use Bitrix\Main\DB\Ddl\Column\ColumnStateData;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlQueryException;

class Database
{
	public static function tableExists(string $tableName, ?Connection $connection = null): bool
	{
		$connection ??= Application::getConnection();

		$tableName = preg_replace("/[^A-Za-z0-9%_]+/", '', $tableName);
		if ($tableName == '')
		{
			return false;
		}
		$preparedTableName = strtolower($connection->getSqlHelper()->forSql($tableName));

		$sql = match (mb_strtolower($connection->getType()))
		{
			'mysql' => "SHOW TABLES LIKE '" . $preparedTableName . "'",
			'pgsql' => "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE '" . $preparedTableName . "'"
		};

		return (bool)$connection->query($sql)->fetch();
	}

	public static function columnExists(string $tableName, string $columnName, ?Connection $connection = null): bool
	{
		$connection ??= Application::getConnection();

		$re = '/[^A-Za-z0-9\_]+/';
		$columnName = preg_replace($re, '', $columnName);
		$tableName = preg_replace($re, '', $tableName);
		if (empty($tableName) || empty($columnName))
		{
			return false;
		}

		try
		{
			$sqlExpression = new SqlExpression(
				'SELECT ?# FROM ?# WHERE 1 = 0',
				$columnName,
				$tableName,
			);
			$sqlExpression->setConnection($connection);
			$connection->query($sqlExpression);

			return true;
		}
		catch (SqlQueryException)
		{
			return false;
		}
	}

	public static function indexExists(string $tableName, array $indexFields, ?Connection $connection = null): bool
	{
		return !is_null(self::getIndexName($tableName, $indexFields, $connection));
	}

	public static function getIndexName(string $tableName, array $indexFields, ?Connection $connection = null): ?string
	{
		$connection ??= Application::getConnection();

		$indexName = $connection->getIndexName($tableName, $indexFields, true);

		return empty($indexName) ? null : $indexName;
	}

	/**
	 * Returns ordered list of column names that form the current primary key.
	 * Empty array if the table has no primary key or doesn't exist.
	 *
	 * @return string[]
	 */
	public static function getPrimaryKeyColumns(string $tableName, ?Connection $connection = null): array
	{
		$connection ??= Application::getConnection();

		$tableName = preg_replace("/[^A-Za-z0-9_]+/", '', $tableName);
		if ($tableName === '')
		{
			return [];
		}

		$preparedTableName = $connection->getSqlHelper()->forSql($tableName);

		$sql = match (mb_strtolower($connection->getType()))
		{
			'mysql' =>
				"SELECT COLUMN_NAME"
				. " FROM information_schema.KEY_COLUMN_USAGE"
				. " WHERE TABLE_SCHEMA = DATABASE()"
				. " AND TABLE_NAME = '" . $preparedTableName . "'"
				. " AND CONSTRAINT_NAME = 'PRIMARY'"
				. " ORDER BY ORDINAL_POSITION",
			'pgsql' =>
				"SELECT a.attname AS column_name"
				. " FROM pg_index i"
				. " JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)"
				. " WHERE i.indrelid = '" . $preparedTableName . "'::regclass"
				. " AND i.indisprimary"
				. " ORDER BY array_position(i.indkey, a.attnum)",
		};

		try
		{
			$result = $connection->query($sql);
		}
		catch (SqlQueryException)
		{
			return [];
		}

		$columns = [];
		while ($row = $result->fetch())
		{
			$columns[] = $row['COLUMN_NAME'] ?? $row['column_name'] ?? null;
		}

		return array_values(array_filter($columns));
	}

	/**
	 * @param string[] $columnNames
	 * @return array<string, ColumnStateData>
	 */
	public static function getPostgreSqlColumnStates(
		string $tableName,
		array $columnNames,
		?Connection $connection = null,
	): array
	{
		$connection ??= Application::getConnection();

		$tableName = strtolower($tableName);
		$columnNames = array_values(array_unique(array_map(strtolower(...), $columnNames)));
		if ($tableName === '' || empty($columnNames))
		{
			return [];
		}

		$sqlExpression = new SqlExpression(
			<<<'SQL'
SELECT
	a.attname AS column_name,
	format_type(a.atttypid, NULL) AS column_type,
	CASE WHEN a.attidentity <> '' THEN 'YES' ELSE 'NO' END AS is_identity,
	pg_get_expr(ad.adbin, ad.adrelid) AS column_default,
	sequence_namespace.nspname AS sequence_schema,
	sequence.relname AS sequence_name,
	format_type(sequence_data.seqtypid, NULL) AS sequence_type
FROM pg_catalog.pg_class table_data
JOIN pg_catalog.pg_namespace table_namespace ON table_namespace.oid = table_data.relnamespace
JOIN pg_catalog.pg_attribute a ON a.attrelid = table_data.oid
LEFT JOIN pg_catalog.pg_attrdef ad ON ad.adrelid = table_data.oid AND ad.adnum = a.attnum
LEFT JOIN pg_catalog.pg_depend dependency
	ON dependency.classid = 'pg_catalog.pg_attrdef'::regclass
	AND dependency.objid = ad.oid
	AND dependency.refclassid = 'pg_catalog.pg_class'::regclass
	AND dependency.deptype IN ('a', 'n')
LEFT JOIN pg_catalog.pg_class sequence
	ON sequence.oid = dependency.refobjid
	AND sequence.relkind = 'S'
LEFT JOIN pg_catalog.pg_namespace sequence_namespace ON sequence_namespace.oid = sequence.relnamespace
LEFT JOIN pg_catalog.pg_sequence sequence_data ON sequence_data.seqrelid = sequence.oid
WHERE table_namespace.nspname = current_schema()
	AND table_data.relname = ?s
	AND a.attnum > 0
	AND NOT a.attisdropped
	AND a.attname IN (?@)
SQL,
			$tableName,
			$columnNames,
		);
		$sqlExpression->setConnection($connection);
		$result = $connection->query($sqlExpression);

		$states = [];
		while ($row = $result->fetch())
		{
			$columnName = strtolower($row['column_name'] ?? $row['COLUMN_NAME']);
			$isIdentity = ($row['is_identity'] ?? $row['IS_IDENTITY']) === 'YES';
			$columnDefault = $row['column_default'] ?? $row['COLUMN_DEFAULT'] ?? null;
			$autoincrementType = match (true)
			{
				$isIdentity => ColumnAutoincrementType::Identity,
				is_string($columnDefault) && preg_match('/^nextval\s*\(/i', $columnDefault) => ColumnAutoincrementType::SequenceDefault,
				default => ColumnAutoincrementType::None,
			};

			$states[$columnName] = new ColumnStateData(
				type: self::normalizePostgreSqlIntegerType($row['column_type'] ?? $row['COLUMN_TYPE']),
				autoincrementType: $autoincrementType,
				sequenceSchema: $row['sequence_schema'] ?? $row['SEQUENCE_SCHEMA'] ?? null,
				sequenceName: $row['sequence_name'] ?? $row['SEQUENCE_NAME'] ?? null,
				sequenceType: isset($row['sequence_type']) || isset($row['SEQUENCE_TYPE'])
					? self::normalizePostgreSqlIntegerType($row['sequence_type'] ?? $row['SEQUENCE_TYPE'])
					: null,
			);
		}

		return $states;
	}

	private static function normalizePostgreSqlIntegerType(string $type): string
	{
		return match (strtolower($type))
		{
			'int2', 'smallint' => 'smallint',
			'int4', 'int', 'integer' => 'integer',
			'int8', 'bigint' => 'bigint',
			default => strtolower($type),
		};
	}
}

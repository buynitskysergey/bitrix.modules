<?php
namespace Bitrix\BIConnector\Configuration;

use Bitrix\Bitrix24;
use Bitrix\Main;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * Class Feature
 * Provides unified methods for check B24 tariff restrictions.
 *
 * @package Bitrix\BIConnector\Configuration
 */
final class Feature
{
	public const BI_BUILDER = 'bi_constructor';
	public const BI_BUILDER_RIGHTS = 'bi_constructor_rights';
	public const BI_EXTERNAL_ENTITIES = 'bi_constructor_external_entities';
	public const BI_BUILDER_EXPORT = 'bi_constructor_export';
	public const BI_BUILDER_EXTERNAL_SQL_DATASET = 'bi_constructor_external_sql_dataset';
	public const CHECK_PERMISSION_BY_GROUP_OPTION = 'check_permissions_by_group';
	public const BITRIXGPT_BI_CONSTRUCTOR = 'bitrixgpt_bi_constructor';
	public const AGGREGATION_PUSHDOWN = 'aggregation_pushdown';
	public const BI_DEMO_REPORTS = 'bi_demo_reports';
	public const BI_CLICKHOUSE_SOURCE = 'bi_clickhouse_source';

	public static function isBuilderEnabled(): bool
	{
		return self::isFeatureEnabled(self::BI_BUILDER);
	}

	public static function isBiBuilderRightsEnabled(): bool
	{
		return self::isBuilderEnabled() && self::isFeatureEnabled(self::BI_BUILDER_RIGHTS);
	}

	public static function isExternalEntitiesEnabled(): bool
	{
		return
			self::isBuilderEnabled()
			&& self::isFeatureEnabled(self::BI_EXTERNAL_ENTITIES)
		;
	}

	public static function isSourceExpensesEnabled(): bool
	{
		return self::isExternalEntitiesEnabled();
	}

	public static function isBiBuilderExportEnabled(): bool
	{
		return
			self::isBuilderEnabled()
			&& self::isFeatureEnabled(self::BI_BUILDER_EXPORT)
		;
	}

	public static function isExternalSqlDatasetEnabled(): bool
	{
		return
			self::isBuilderEnabled()
			&& !Loader::includeModule('bitrix24')
		;
	}

	/**
	 * ClickHouse as an external source, shipped behind an option of its own.
	 *
	 * The source rides on the same path as the other external SQL sources, so it is available where
	 * they are - and only while the option is on, which keeps the first delivery off every portal that
	 * did not ask for it.
	 *
	 * @return bool
	 */
	public static function isClickhouseSourceEnabled(): bool
	{
		return
			self::isExternalSqlDatasetEnabled()
			&& self::checkFeatureOption(self::BI_CLICKHOUSE_SOURCE)
		;
	}

	public static function isCheckPermissionsByGroup(): bool
	{
		return self::checkFeatureOption(self::CHECK_PERMISSION_BY_GROUP_OPTION);
	}

	public static function isBitrixGptBiConstructorEnabled(): bool
	{
		return self::checkFeatureOption(self::BITRIXGPT_BI_CONSTRUCTOR);
	}

	/**
	 * Turning the option off stops publishing capabilities and accepting aggregation blocks at once:
	 * the plugin then plans without pushdown, which degrades performance but never the numbers.
	 */
	public static function isAggregationPushdownEnabled(): bool
	{
		return self::isBuilderEnabled() && self::checkFeatureOption(self::AGGREGATION_PUSHDOWN);
	}

	public static function isDemoReportsEnabled(): bool
	{
		return self::checkFeatureOption(self::BI_DEMO_REPORTS);
	}

	public static function checkFeatureOption(string $optionName): bool
	{
		return Option::get('biconnector', $optionName, 'N') === 'Y';
	}

	/**
	 * @param string $featureId
	 * @return bool
	 *
	 * @throws Main\LoaderException
	 */
	private static function isFeatureEnabled(string $featureId): bool
	{
		if (Loader::includeModule('bitrix24'))
		{
			return Bitrix24\Feature::isFeatureEnabled($featureId);
		}

		return true;
	}
}

<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

use Bitrix\BIConnector\Configuration\DataTimezone;
use Bitrix\BIConnector\DB\BiAggregationDialectInterface;

/**
 * The `where` block of the aggregation capabilities (design §5.11.2, schema §2.7).
 *
 * It declares what the portal applies exactly, so that the plugin may drop those conjuncts from the
 * residual and reach the `Aggregation(TableScan)` plan shape at all. Everything the block does not
 * claim stays with the engine, which is always correct and merely slower.
 *
 * The block is additive: a plugin that does not read it keeps the previous behaviour, and a portal
 * that does not publish it forbids narrowing.
 */
final class WhereCapabilities
{
	/**
	 * @param array<int, array{from: string, to: string}> $dateRangeIntervals Empty means "no exact interval".
	 * @param string[] $inexactColumns
	 * @param array<string, string[]> $exactOperators
	 */
	private function __construct(
		public readonly bool $appliedFingerprint,
		public readonly array $dateRangeIntervals,
		public readonly array $inexactColumns,
		public readonly array $exactOperators,
	)
	{
	}

	public static function build(BiAggregationDialectInterface $dialect, DatasetSchema $schema): self
	{
		return new self(
			// The portal always echoes the fingerprint of the WHERE it actually compiled, which is what
			// lets the plugin fail fast instead of trusting that the filter was applied.
			appliedFingerprint: true,
			dateRangeIntervals: TimezoneExactIntervals::forTimezone(self::getTimezone()),
			inexactColumns: $dialect->getInexactWhereColumns($schema),
			exactOperators: $dialect->getExactWhereOperators(),
		);
	}

	public function toArray(): array
	{
		return [
			'appliedFingerprint' => $this->appliedFingerprint,
			// `false` and not an empty array: the schema models "no exact interval at all" as a boolean.
			'dateRange' => $this->dateRangeIntervals === []
				? false
				: ['exactIntervals' => array_values($this->dateRangeIntervals)]
			,
			'inexactColumns' => array_values($this->inexactColumns),
			'exactOperators' => $this->exactOperators,
		];
	}

	private static function getTimezone(): ?string
	{
		// No configured offset means the raw path applies no conversion, so no day boundary can shift.
		if (!DataTimezone::getTimezoneOffset())
		{
			return null;
		}

		$timezone = DataTimezone::getTimezone();

		return is_string($timezone) && $timezone !== '' ? $timezone : null;
	}
}

<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

use Bitrix\BIConnector\DB\BiAggregationDialectInterface;

/**
 * The `capabilities.aggregation` block of `?desc&caps=1` (design §2.1).
 *
 * The fingerprint is content addressed: it is the SHA-256 of the canonical form of the block
 * without the fingerprint field itself. Any change of the content - a different source behind the
 * same table name, a flag, an excluded column - changes it, and no persistent counter, atomic
 * increment or cluster coordination is needed.
 */
final class Capabilities
{
	public const PROTOCOL_VERSIONS = [1];

	public const FUNCTION_COUNT = 'COUNT';
	public const FUNCTION_COUNT_DISTINCT = 'COUNT_DISTINCT';
	public const FUNCTION_SUM = 'SUM';
	public const FUNCTION_SUM_DISTINCT = 'SUM_DISTINCT';
	public const FUNCTION_AVG = 'AVG';
	public const FUNCTION_AVG_DISTINCT = 'AVG_DISTINCT';
	public const FUNCTION_MIN = 'MIN';
	public const FUNCTION_MAX = 'MAX';

	/**
	 * Order of the dictionary in design §2.1.
	 *
	 * Canonicalization sorts object keys but preserves array order, so this order is part of the
	 * capabilities fingerprint and has to reproduce the normative example byte for byte.
	 */
	private const FUNCTION_ORDER = [
		self::FUNCTION_COUNT,
		self::FUNCTION_COUNT_DISTINCT,
		self::FUNCTION_SUM,
		self::FUNCTION_SUM_DISTINCT,
		self::FUNCTION_AVG,
		self::FUNCTION_AVG_DISTINCT,
		self::FUNCTION_MIN,
		self::FUNCTION_MAX,
	];

	/**
	 * @param string[] $functions
	 * @param string[] $dateTruncUnits
	 * @param string[] $excludedColumns
	 */
	private function __construct(
		public readonly bool $supported,
		public readonly array $protocolVersions = [],
		public readonly string $rowSetSemantics = '',
		public readonly array $functions = [],
		public readonly array $dateTruncUnits = [],
		public readonly bool $having = false,
		public readonly bool $groupLimit = false,
		public readonly bool $orderBy = false,
		public readonly array $excludedColumns = [],
		public readonly ?DialectSemantics $semantics = null,
		public readonly string $scopeId = '',
		public readonly ?WhereCapabilities $where = null,
	)
	{
	}

	public static function unsupported(): self
	{
		return new self(false);
	}

	/**
	 * Builds the block for a dataset served by the given dialect.
	 *
	 * A null dialect means the connection is not SQL or its helper does not implement the
	 * aggregation contract, which is reported as `supported: false`.
	 */
	public static function build(
		?BiAggregationDialectInterface $dialect,
		DatasetSchema $schema,
		string $scopeId,
	): self
	{
		if ($dialect === null || $scopeId === '')
		{
			return self::unsupported();
		}

		$rowSetSemantics = $schema->getRowSetSemantics();
		if ($rowSetSemantics !== DatasetSchema::ROW_SET_RAW)
		{
			// The plugin refuses anything but RAW anyway; answering `false` keeps the block minimal.
			return self::unsupported();
		}

		return new self(
			supported: true,
			protocolVersions: self::PROTOCOL_VERSIONS,
			rowSetSemantics: $rowSetSemantics,
			functions: self::buildFunctions($dialect),
			dateTruncUnits: DateTruncSpec::UNITS,
			having: true,
			groupLimit: true,
			orderBy: true,
			excludedColumns: self::buildExcludedColumns($dialect, $schema),
			semantics: $dialect->getSemantics(),
			scopeId: $scopeId,
			where: WhereCapabilities::build($dialect, $schema),
		);
	}

	public function isColumnExcluded(string $name): bool
	{
		return in_array($name, $this->excludedColumns, true);
	}

	public function supportsFunction(string $function, bool $distinct): bool
	{
		$name = match ($function)
		{
			AggregateItem::COUNT => $distinct ? self::FUNCTION_COUNT_DISTINCT : self::FUNCTION_COUNT,
			AggregateItem::SUM => $distinct ? self::FUNCTION_SUM_DISTINCT : self::FUNCTION_SUM,
			AggregateItem::AVG => $distinct ? self::FUNCTION_AVG_DISTINCT : self::FUNCTION_AVG,
			AggregateItem::MIN => self::FUNCTION_MIN,
			AggregateItem::MAX => self::FUNCTION_MAX,
			default => null,
		};

		return $name !== null && in_array($name, $this->functions, true);
	}

	public function supportsTruncUnit(string $unit): bool
	{
		return in_array($unit, $this->dateTruncUnits, true);
	}

	/**
	 * The block without the fingerprint field, which is what the fingerprint is computed over.
	 */
	public function toArrayWithoutFingerprint(): array
	{
		if (!$this->supported)
		{
			return ['supported' => false];
		}

		$block = [
			'supported' => true,
			'protocolVersions' => array_values($this->protocolVersions),
			'rowSetSemantics' => $this->rowSetSemantics,
			'functions' => array_values($this->functions),
			'dateTruncUnits' => array_values($this->dateTruncUnits),
			'having' => $this->having,
			'groupLimit' => $this->groupLimit,
			'orderBy' => $this->orderBy,
			'excludedColumns' => array_values($this->excludedColumns),
			'semantics' => $this->semantics?->toArray() ?? [],
			'scopeId' => $this->scopeId,
		];

		// Additive: a plugin that does not read the block keeps its previous behaviour, and its absence
		// forbids narrowing the residual altogether.
		if ($this->where !== null)
		{
			$block['where'] = $this->where->toArray();
		}

		return $block;
	}

	public function getFingerprint(): string
	{
		return CanonicalJson::fingerprint($this->toArrayWithoutFingerprint());
	}

	public function toArray(): array
	{
		if (!$this->supported)
		{
			return ['supported' => false];
		}

		$block = $this->toArrayWithoutFingerprint();
		$block['capsFingerprint'] = $this->getFingerprint();

		return $block;
	}

	/**
	 * @return string[]
	 */
	private static function buildFunctions(BiAggregationDialectInterface $dialect): array
	{
		$exactCountDistinct = $dialect->supportsExactCountDistinct();

		$functions = [];
		foreach (self::FUNCTION_ORDER as $function)
		{
			// An approximate COUNT(DISTINCT) must never be advertised: it would be a wrong number.
			if ($function === self::FUNCTION_COUNT_DISTINCT && !$exactCountDistinct)
			{
				continue;
			}

			$functions[] = $function;
		}

		return $functions;
	}

	/**
	 * Columns that take no part in a delegated aggregation in any role (design §2.1, §6.5).
	 *
	 * @return string[]
	 */
	private static function buildExcludedColumns(
		BiAggregationDialectInterface $dialect,
		DatasetSchema $schema,
	): array
	{
		$excluded = [];
		foreach (array_keys($schema->getRawFields()) as $name)
		{
			$name = (string)$name;

			if ($schema->hasCallback($name) || $schema->isNonAggregatableType($name))
			{
				$excluded[] = $name;

				continue;
			}

			if ($schema->getFieldWireType($name) !== 'BOOLEAN')
			{
				continue;
			}

			// A `bool` field on a character carrier has no truth table anywhere in the code, so no
			// normalizing expression can be derived and the column is excluded by name.
			$wireExpression = $dialect->getBooleanWireExpression('x', $schema->getFieldNativeSqlType($name));
			if ($wireExpression === null)
			{
				$excluded[] = $name;
			}
		}

		sort($excluded);

		return $excluded;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration;

use Bitrix\Main\DB\Ddl\IndexColumn;

final class IndexNameProcessor
{
	private const MAX_IDENTIFIER_LENGTH = 63;
	private const SHORT_NAME_LENGTH = 50;
	private const HASH_LENGTH = 12;

	public function __construct(
		private readonly Context $context,
	)
	{
	}

	/**
	 * @param IndexColumn[] $columns
	 */
	public function process(string $type, string $tableName, string $indexName, array $columns): string
	{
		$isStrictCurrentDev = $this->context->isCurrentDevUpdater();
		if ($isStrictCurrentDev)
		{
			$this->validate($type, $tableName, $indexName);
		}

		if (!$this->context->isPostgreSql())
		{
			return $indexName;
		}

		$normalizedFullName = $isStrictCurrentDev
			? strtolower($indexName)
			: $this->normalize($type, $tableName, $indexName)
		;

		$physicalName = $normalizedFullName;
		if (strlen($physicalName) > self::MAX_IDENTIFIER_LENGTH)
		{
			$definition = $this->buildDefinition($type, $tableName, $normalizedFullName, $columns);
			$physicalName = substr($normalizedFullName, 0, self::SHORT_NAME_LENGTH)
				. '_'
				. substr(hash('sha256', $definition), 0, self::HASH_LENGTH)
			;
		}

		return $physicalName;
	}

	private function validate(string $type, string $tableName, string $indexName): void
	{
		$expectedPrefix = $this->getPrefix($type);
		$tableSegments = [$tableName];
		if (str_starts_with(strtolower($tableName), 'b_'))
		{
			$tableSegments[] = substr($tableName, 2);
		}
		$expectedFormat = $expectedPrefix . '(' . implode('|', $tableSegments) . ')_<purpose>';

		if (strlen($indexName) > self::MAX_IDENTIFIER_LENGTH)
		{
			$this->throwValidationException($tableName, $indexName, $expectedFormat, 'too_long');
		}

		if (preg_match('/^[A-Za-z]/D', $indexName) !== 1)
		{
			$this->throwValidationException($tableName, $indexName, $expectedFormat, 'invalid_first_character');
		}

		if (preg_match('/^[A-Za-z0-9_]+$/D', $indexName) !== 1)
		{
			$this->throwValidationException($tableName, $indexName, $expectedFormat, 'invalid_characters');
		}

		if (strncasecmp($indexName, $expectedPrefix, strlen($expectedPrefix)) !== 0)
		{
			$this->throwValidationException($tableName, $indexName, $expectedFormat, 'prefix_mismatch');
		}

		$purpose = substr($indexName, strlen($expectedPrefix));
		foreach ($tableSegments as $tableSegment)
		{
			$segmentWithSeparator = $tableSegment . '_';
			if (strncasecmp($purpose, $segmentWithSeparator, strlen($segmentWithSeparator)) === 0)
			{
				if (strlen($purpose) === strlen($segmentWithSeparator))
				{
					$this->throwValidationException($tableName, $indexName, $expectedFormat, 'empty_suffix');
				}

				return;
			}
		}

		$this->throwValidationException($tableName, $indexName, $expectedFormat, 'table_segment_mismatch');
	}

	private function throwValidationException(
		string $tableName,
		string $indexName,
		string $expectedFormat,
		string $reason,
	): never
	{
		$moduleId = $this->context->getModuleId();

		throw new Exception(
			$moduleId,
			1108,
			'Invalid index name "' . $indexName . '" for module "' . $moduleId . '" in table "' . $tableName
				. '": ' . $reason . '; expected format ' . $expectedFormat,
			[
				'table' => $tableName,
				'index' => $indexName,
				'reason' => $reason,
				'expectedFormat' => $expectedFormat,
			],
		);
	}

	private function normalize(string $type, string $tableName, string $indexName): string
	{
		$purpose = preg_replace('/^(?:ixf_|ix_|ux_|tx_)/', '', strtolower($indexName));
		$purpose ??= strtolower($indexName);
		$tableName = strtolower($tableName);

		if (!$this->hasTableSegment($purpose, $tableName))
		{
			$purpose = $tableName . '_' . $purpose;
		}

		return $this->getPrefix($type) . $purpose;
	}

	private function hasTableSegment(string $purpose, string $tableName): bool
	{
		$tableSegments = [$tableName];
		if (str_starts_with($tableName, 'b_'))
		{
			$tableSegments[] = substr($tableName, 2);
		}

		foreach ($tableSegments as $tableSegment)
		{
			if (str_starts_with($purpose, $tableSegment . '_') && strlen($purpose) > strlen($tableSegment) + 1)
			{
				return true;
			}
		}

		return false;
	}

	private function getPrefix(string $type): string
	{
		return match ($type)
		{
			'unique' => 'ux_',
			'fulltext' => 'ixf_',
			default => 'ix_',
		};
	}

	/**
	 * @param IndexColumn[] $columns
	 */
	private function buildDefinition(
		string $type,
		string $tableName,
		string $normalizedFullName,
		array $columns,
	): string
	{
		$values = [$type, $tableName, $normalizedFullName];
		foreach ($columns as $column)
		{
			$values[] = $column->name;
			$values[] = $column->length === null ? null : (string)$column->length;
			$values[] = $column->sort;
		}

		return $this->encodeValues($values);
	}

	/**
	 * @param array<int, ?string> $values
	 */
	private function encodeValues(array $values): string
	{
		$result = '';
		foreach ($values as $value)
		{
			$result .= $value === null ? '-1:' : strlen($value) . ':' . $value;
		}

		return $result;
	}
}

<?php

namespace Bitrix\Main\Infrastructure\Rest\Support;

use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;

final class LegacyQueryConverter
{
	public static function filterToLegacy(?FilterStructure $filter): array
	{
		if ($filter === null)
		{
			return [];
		}

		// Always use the full filter tree. The simple-path shortcut collapses OR/NOT
		// when getSimpleFilterConditions() returns a partial flat map.
		$list = $filter->getList();
		if ($list === [])
		{
			return [];
		}

		return self::convertFilterNode($list);
	}

	public static function orderToLegacy(?OrderStructure $order): array
	{
		if ($order === null)
		{
			return [];
		}

		$converter = new Converter(Converter::TO_UPPER | Converter::TO_SNAKE | Converter::KEYS);

		return $converter->process($order->getList());
	}

	public static function startFromPagination(?PaginationStructure $pagination): int
	{
		return $pagination?->getOffset() ?? 0;
	}

	/**
	 * Converts bool filter values to legacy Y/N for ORM boolean fields.
	 *
	 * @param list<string> $ormFields Field names without operator prefix (e.g. ACTIVE, MULTIPLE).
	 */
	public static function convertBoolFilterValuesToYn(array $filter, array $ormFields): array
	{
		if ($filter === [] || $ormFields === [])
		{
			return $filter;
		}

		$ormFieldsMap = array_fill_keys($ormFields, true);
		$result = [];

		foreach ($filter as $key => $value)
		{
			if (is_int($key) && is_array($value))
			{
				$result[$key] = self::convertBoolFilterValuesToYn($value, $ormFields);
				continue;
			}

			if ($key === 'LOGIC')
			{
				$result[$key] = $value;
				continue;
			}

			$fieldName = self::extractFilterFieldName((string)$key);
			if (isset($ormFieldsMap[$fieldName]))
			{
				$value = self::boolToYnValue($value);
			}

			$result[$key] = $value;
		}

		return $result;
	}

	/**
	 * @param list<string> $dtoFields CamelCase DTO property names (e.g. multiple, isSearchable).
	 */
	public static function convertBoolFieldsToYn(array $fields, array $dtoFields): array
	{
		foreach ($dtoFields as $dtoField)
		{
			if (!array_key_exists($dtoField, $fields) || !is_bool($fields[$dtoField]))
			{
				continue;
			}

			$fields[$dtoField] = $fields[$dtoField] ? 'Y' : 'N';
		}

		return $fields;
	}

	public static function ynToBool(mixed $value): ?bool
	{
		if ($value === null)
		{
			return null;
		}

		if (is_bool($value))
		{
			return $value;
		}

		return $value === 'Y';
	}

	/**
	 * Applies V3 list pagination to an in-memory collection.
	 *
	 * @template T
	 * @param list<T> $items
	 * @return list<T>
	 */
	public static function sliceByPagination(array $items, ?PaginationStructure $pagination): array
	{
		$limit = $pagination?->getLimit() ?? PaginationStructure::DEFAULT_LIMIT;
		$offset = $pagination?->getOffset() ?? 0;

		return array_values(array_slice($items, $offset, $limit));
	}

	private static function boolToYnValue(mixed $value): mixed
	{
		if (is_bool($value))
		{
			return $value ? 'Y' : 'N';
		}

		if (is_array($value))
		{
			return array_map(static fn($item) => self::boolToYnValue($item), $value);
		}

		return $value;
	}

	private static function extractFilterFieldName(string $key): string
	{
		return ltrim($key, '=!<>@%*');
	}

	private static function convertFilterNode(array $node): array
	{
		if (($node['type'] ?? null) === 'condition')
		{
			$node = [
				$node['leftOperand'] ?? '',
				$node['operator'] ?? '=',
				$node['rightOperand'] ?? null,
			];
		}

		if (isset($node['conditions']))
		{
			$result = [
				'LOGIC' => strtoupper((string)($node['logic'] ?? 'and')),
			];
			foreach ($node['conditions'] as $condition)
			{
				$result[] = self::convertFilterNode($condition);
			}

			if (($node['negative'] ?? false) === true)
			{
				// Classic ORM array-filter supports only AND/OR at LOGIC level.
				// Express NOT via operator inversion (De Morgan for groups).
				return self::negateFilter(self::collapseTopLevelAnd($result));
			}

			return self::collapseTopLevelAnd($result);
		}

		if (isset($node[0]) && is_array($node[0]))
		{
			$result = [
				'LOGIC' => 'AND',
			];
			foreach ($node as $condition)
			{
				$result[] = self::convertFilterNode($condition);
			}

			return self::collapseTopLevelAnd($result);
		}

		$field = self::convertFieldName((string)($node[0] ?? ''));
		$operator = count($node) === 2
			? (is_array($node[1]) ? 'in' : '=')
			: (string)($node[1] ?? '=');
		$value = count($node) === 2 ? ($node[1] ?? null) : ($node[2] ?? null);

		// Legacy IRestService::sanitizeFilter keeps the operator prefix on the field key.
		// For equality it must be "=" (bare FIELD is treated as empty operation and rejected
		// by handlers that allow only "=" for USER_ID / HISTORY_ID / FIELD).
		$prefix = match ($operator)
		{
			'=' => '=',
			'!=' => '!=',
			'>' => '>',
			'>=' => '>=',
			'<' => '<',
			'<=' => '<=',
			'in' => '@',
			'between' => '><',
			default => '=',
		};

		return [
			$prefix . $field => $value,
		];
	}

	/**
	 * Flattens a non-negative top-level AND group into a plain filter when possible,
	 * while keeping nested OR/NOT groups intact as siblings under LOGIC=AND.
	 */
	private static function collapseTopLevelAnd(array $filter): array
	{
		if (($filter['LOGIC'] ?? null) !== 'AND')
		{
			return $filter;
		}

		$flat = [];
		$groups = [];

		foreach ($filter as $key => $value)
		{
			if ($key === 'LOGIC')
			{
				continue;
			}

			if (!is_array($value))
			{
				continue;
			}

			if (isset($value['LOGIC']))
			{
				$groups[] = $value;
				continue;
			}

			$isSingleFieldCondition = true;
			foreach ($value as $fieldKey => $fieldValue)
			{
				if (!is_string($fieldKey))
				{
					$isSingleFieldCondition = false;
					break;
				}
			}

			if ($isSingleFieldCondition)
			{
				foreach ($value as $fieldKey => $fieldValue)
				{
					if (array_key_exists($fieldKey, $flat))
					{
						// Keep both predicates under AND: id=1 AND id=2 must stay contradictory.
						$groups[] = [$fieldKey => $flat[$fieldKey]];
						unset($flat[$fieldKey]);
						$groups[] = [$fieldKey => $fieldValue];
					}
					else
					{
						$flat[$fieldKey] = $fieldValue;
					}
				}
				continue;
			}

			$groups[] = $value;
		}

		if ($groups === [])
		{
			return $flat;
		}

		$result = [
			'LOGIC' => 'AND',
		];
		if ($flat !== [])
		{
			$result[] = $flat;
		}
		foreach ($groups as $group)
		{
			$result[] = $group;
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $filter
	 * @return array<string, mixed>
	 */
	private static function negateFilter(array $filter): array
	{
		if (isset($filter['LOGIC']))
		{
			$logic = strtoupper((string)$filter['LOGIC']);
			$negatedLogic = $logic === 'OR' ? 'AND' : 'OR';
			$result = ['LOGIC' => $negatedLogic];
			foreach ($filter as $key => $value)
			{
				if ($key === 'LOGIC')
				{
					continue;
				}
				$result[] = is_array($value) ? self::negateFilter($value) : $value;
			}

			return $result;
		}

		$result = [];
		foreach ($filter as $key => $value)
		{
			if (!is_string($key))
			{
				$result[$key] = is_array($value) ? self::negateFilter($value) : $value;
				continue;
			}

			$result[self::negateOperatorKey($key)] = $value;
		}

		return $result;
	}

	private static function negateOperatorKey(string $key): string
	{
		$field = self::extractFilterFieldName($key);
		$operator = substr($key, 0, strlen($key) - strlen($field));

		$negated = match ($operator)
		{
			'=' => '!=',
			'!=' => '=',
			'>' => '<=',
			'>=' => '<',
			'<' => '>=',
			'<=' => '>',
			'@' => '!@',
			'!@' => '@',
			'><' => '!><',
			'!><' => '><',
			'%' => '!%',
			'!%' => '%',
			'' => '!',
			'!' => '',
			default => '!=',
		};

		return $negated . $field;
	}

	private static function convertFieldName(string $fieldName): string
	{
		return (new Converter(Converter::TO_UPPER | Converter::TO_SNAKE))->process($fieldName);
	}
}

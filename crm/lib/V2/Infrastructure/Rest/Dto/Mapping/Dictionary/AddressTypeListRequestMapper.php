<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary;

use Bitrix\Crm\V2\Public\Entity\Item\AddressType;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\InvalidOrderException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\Expression;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;

final class AddressTypeListRequestMapper
{
	private const FIELDS = ['id', 'name', 'title'];

	private const FILTER_OPERATORS = [Operator::Equal, Operator::NotEqual, Operator::In];

	/**
	 * @param list<AddressType> $items
	 * @return list<AddressType>
	 */
	public function map(array $items, ListRequest $request): array
	{
		$items = $this->filter($items, $request->filter);
		$this->sort($items, $request);

		return $this->paginate($items, $request);
	}

	/**
	 * @param list<AddressType> $items
	 * @return list<AddressType>
	 */
	private function filter(array $items, ?FilterStructure $filter): array
	{
		if ($filter === null || ($filter->getConditions() === [] && !$filter->isNegative()))
		{
			return $items;
		}

		return array_values(array_filter(
			$items,
			fn(AddressType $item): bool => $this->matchesFilter($item, $filter),
		));
	}

	private function matchesFilter(AddressType $item, FilterStructure $filter): bool
	{
		$conditions = $filter->getConditions();
		if ($conditions === [] || $filter->isNegative())
		{
			throw new InvalidFilterException($filter->getList());
		}

		$matches = [];
		foreach ($conditions as $condition)
		{
			$matches[] = $condition instanceof FilterStructure
				? $this->matchesFilter($item, $condition)
				: $this->matchesCondition($item, $condition)
			;
		}

		return $filter->logic() === Logic::And
			? !in_array(false, $matches, true)
			: in_array(true, $matches, true)
		;
	}

	private function matchesCondition(AddressType $item, Condition $condition): bool
	{
		$field = $condition->getLeftOperand();
		$value = $condition->getRightOperand();
		$operator = $condition->getOperator();
		if (
			!is_string($field)
			|| !in_array($field, self::FIELDS, true)
			|| !in_array($operator, self::FILTER_OPERATORS, true)
			|| $this->containsExpression($value)
		)
		{
			throw new InvalidFilterException($condition->getOperands());
		}

		$fieldValue = $this->getFieldValue($item, $field);

		return match ($operator)
		{
			Operator::Equal => $fieldValue === $value,
			Operator::NotEqual => $fieldValue !== $value,
			Operator::In => $this->matchesIn($fieldValue, $value, $condition),
			default => throw new InvalidFilterException($condition->getOperands()),
		};
	}

	private function matchesIn(int|string $fieldValue, mixed $value, Condition $condition): bool
	{
		if (!is_array($value))
		{
			throw new InvalidFilterException($condition->getOperands());
		}

		return in_array($fieldValue, $value, true);
	}

	private function containsExpression(mixed $value): bool
	{
		if ($value instanceof Expression)
		{
			return true;
		}
		if (!is_array($value))
		{
			return false;
		}

		foreach ($value as $item)
		{
			if ($this->containsExpression($item))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<AddressType> $items
	 */
	private function sort(array &$items, ListRequest $request): void
	{
		$order = [];
		foreach ($request->order?->getItems() ?? [] as $orderItem)
		{
			$field = $orderItem->getProperty();
			$direction = $orderItem->getOrder()->value;
			if (!in_array($field, self::FIELDS, true) || isset($order[$field]))
			{
				throw new InvalidOrderException($field);
			}
			if (!in_array($direction, ['ASC', 'DESC'], true))
			{
				throw new InvalidOrderException($direction);
			}

			$order[$field] = $direction;
		}
		if ($order === [])
		{
			return;
		}

		if (!isset($order['id']))
		{
			$order['id'] = 'ASC';
		}

		usort($items, function (AddressType $left, AddressType $right) use ($order): int {
			foreach ($order as $field => $direction)
			{
				$leftValue = $this->getFieldValue($left, $field);
				$rightValue = $this->getFieldValue($right, $field);
				$comparison = is_int($leftValue)
					? $leftValue <=> $rightValue
					: strcmp($leftValue, $rightValue)
				;
				if ($comparison !== 0)
				{
					return $direction === 'ASC' ? $comparison : -$comparison;
				}
			}

			return 0;
		});
	}

	private function getFieldValue(AddressType $item, string $field): int|string
	{
		return match ($field)
		{
			'id' => $item->getId(),
			'name' => $item->getName(),
			'title' => $item->getTitle(),
			default => throw new InvalidFilterException([$field]),
		};
	}

	/**
	 * @param list<AddressType> $items
	 * @return list<AddressType>
	 */
	private function paginate(array $items, ListRequest $request): array
	{
		if ($request->pagination === null)
		{
			return $items;
		}

		$limit = $request->pagination->getLimit();
		$offset = $request->pagination->getOffset();
		if ($limit <= 0)
		{
			throw new InvalidPaginationException(['limit' => $limit]);
		}
		if ($offset < 0)
		{
			throw new InvalidPaginationException(['offset' => $offset]);
		}

		return array_slice($items, $offset, $limit);
	}
}

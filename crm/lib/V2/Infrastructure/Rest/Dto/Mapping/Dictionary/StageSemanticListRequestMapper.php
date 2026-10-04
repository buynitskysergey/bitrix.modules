<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary;

use Bitrix\Crm\V2\Public\Entity\Item\StageSemantic;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\InvalidOrderException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\Expression;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;

final class StageSemanticListRequestMapper
{
	private const FIELDS = ['id', 'name'];

	private const FILTER_OPERATORS = [Operator::Equal, Operator::NotEqual, Operator::In];

	/**
	 * @param list<StageSemantic> $semantics
	 * @return list<StageSemantic>
	 */
	public function map(array $semantics, ListRequest $request): array
	{
		$semantics = $this->filter($semantics, $request->filter);
		$this->sort($semantics, $request);

		return $this->paginate($semantics, $request);
	}

	/**
	 * @param list<StageSemantic> $semantics
	 * @return list<StageSemantic>
	 */
	private function filter(array $semantics, ?FilterStructure $filter): array
	{
		if ($filter === null || ($filter->getConditions() === [] && !$filter->isNegative()))
		{
			return $semantics;
		}

		return array_values(array_filter(
			$semantics,
			fn(StageSemantic $semantic): bool => $this->matchesFilter($semantic, $filter),
		));
	}

	private function matchesFilter(StageSemantic $semantic, FilterStructure $filter): bool
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
				? $this->matchesFilter($semantic, $condition)
				: $this->matchesCondition($semantic, $condition)
			;
		}

		return $filter->logic() === Logic::And
			? !in_array(false, $matches, true)
			: in_array(true, $matches, true)
		;
	}

	private function matchesCondition(StageSemantic $semantic, Condition $condition): bool
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

		$fieldValue = $field === 'id' ? $semantic->getId() : $semantic->getName();

		return match ($operator)
		{
			Operator::Equal => $fieldValue === $value,
			Operator::NotEqual => $fieldValue !== $value,
			Operator::In => $this->matchesIn($fieldValue, $value, $condition),
			default => throw new InvalidFilterException($condition->getOperands()),
		};
	}

	private function matchesIn(?string $fieldValue, mixed $value, Condition $condition): bool
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
	 * @param list<StageSemantic> $semantics
	 */
	private function sort(array &$semantics, ListRequest $request): void
	{
		$order = [];
		foreach ($request->order?->getItems() ?? [] as $item)
		{
			$field = $item->getProperty();
			$direction = $item->getOrder()->value;
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
			$order['id'] = 'ASC';
		}
		elseif (!isset($order['id']))
		{
			$order['id'] = 'ASC';
		}

		usort($semantics, function (StageSemantic $left, StageSemantic $right) use ($order): int {
			foreach ($order as $field => $direction)
			{
				$leftValue = $field === 'id' ? $left->getId() : $left->getName();
				$rightValue = $field === 'id' ? $right->getId() : $right->getName();
				$comparison = strcmp((string)$leftValue, (string)$rightValue);
				if ($comparison !== 0)
				{
					return $direction === 'ASC' ? $comparison : -$comparison;
				}
			}

			return 0;
		});
	}

	/**
	 * @param list<StageSemantic> $semantics
	 * @return list<StageSemantic>
	 */
	private function paginate(array $semantics, ListRequest $request): array
	{
		if ($request->pagination === null)
		{
			return $semantics;
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

		return array_slice($semantics, $offset, $limit);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Data\Dictionary;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary\EntityTypeMapper;
use Bitrix\Crm\V2\Public\Entity\Dictionary\EntityType;
use Bitrix\Crm\V2\Public\Provider\Dictionary\EntityTypeProvider;
use Bitrix\Main\DB\Order;
use Bitrix\Rest\V3\Data\Repository;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\ColumnExpression;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\LengthExpression;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

final class EntityTypeRepository extends Repository
{
	private const FIELDS = ['id', 'code', 'title'];

	public function __construct(
		private readonly EntityTypeProvider $provider,
		private readonly EntityTypeMapper $mapper,
		private readonly string $responseLanguage,
	)
	{
	}

	public function getAll(
		?SelectStructure $select = null,
		?FilterStructure $filter = null,
		?OrderStructure $order = null,
		?PaginationStructure $page = null,
	): DtoCollection
	{
		$items = $this->provider->getList($this->responseLanguage);
		$items = $this->applyFilter($items, $filter);
		$items = $this->applyOrder($items, $order);
		$items = $this->applyPagination($items, $page);

		return $this->mapper->mapCollection($items, $select?->getList() ?? []);
	}

	/**
	 * @param EntityType[] $items
	 * @return EntityType[]
	 */
	private function applyFilter(array $items, ?FilterStructure $filter): array
	{
		if ($filter === null || !$this->hasEffectiveConditions($filter))
		{
			return $items;
		}

		return array_values(array_filter(
			$items,
			fn(EntityType $item): bool => $this->matchesFilter($item, $filter),
		));
	}

	private function matchesFilter(EntityType $item, FilterStructure $filter): bool
	{
		$conditions = $filter->getConditions();
		if ($conditions === [])
		{
			$result = true;
		}
		else
		{
			$result = $filter->logic() === Logic::And;
			foreach ($conditions as $condition)
			{
				if ($condition instanceof FilterStructure)
				{
					if (!$this->hasEffectiveConditions($condition))
					{
						continue;
					}

					$matches = $this->matchesFilter($item, $condition);
				}
				else
				{
					$matches = $this->matchesCondition($item, $condition);
				}

				if ($filter->logic() === Logic::And && !$matches)
				{
					$result = false;

					break;
				}
				if ($filter->logic() === Logic::Or && $matches)
				{
					$result = true;

					break;
				}
			}
		}

		return $filter->isNegative() ? !$result : $result;
	}

	private function hasEffectiveConditions(FilterStructure $filter): bool
	{
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof Condition)
			{
				return true;
			}
			if ($condition instanceof FilterStructure && $this->hasEffectiveConditions($condition))
			{
				return true;
			}
		}

		return false;
	}

	private function matchesCondition(EntityType $item, Condition $condition): bool
	{
		$left = $this->resolveOperand($item, $condition->getLeftOperand(), true);
		$right = $this->resolveOperand($item, $condition->getRightOperand());

		return match ($condition->getOperator())
		{
			Operator::Equal => $left === $right,
			Operator::NotEqual => $left !== $right,
			Operator::Greater => $left > $right,
			Operator::GreaterOrEqual => $left >= $right,
			Operator::Less => $left < $right,
			Operator::LessOrEqual => $left <= $right,
			Operator::In => in_array($left, $this->getArrayOperand(Operator::In, $right), true),
			Operator::Between => $this->matchesBetween($left, $right),
		};
	}

	private function matchesBetween(mixed $left, mixed $right): bool
	{
		$range = $this->getArrayOperand(Operator::Between, $right, 2);

		return $left >= $range[0] && $left <= $range[1];
	}

	private function getArrayOperand(Operator $operator, mixed $operand, ?int $expectedCount = null): array
	{
		if (!is_array($operand) || ($expectedCount !== null && count($operand) !== $expectedCount))
		{
			throw new InvalidFilterException([$operator->value, $operand]);
		}

		return $operand;
	}

	private function resolveOperand(EntityType $item, mixed $operand, bool $isLeftOperand = false): mixed
	{
		if ($operand instanceof ColumnExpression)
		{
			return $this->getItemFieldValue($item, $operand->getProperty());
		}
		if ($operand instanceof LengthExpression)
		{
			return mb_strlen((string)$this->getItemFieldValue($item, $operand->getProperty()));
		}

		if ($isLeftOperand && is_string($operand) && in_array($operand, self::FIELDS, true))
		{
			return $this->getItemFieldValue($item, $operand);
		}

		return $operand;
	}

	/**
	 * @param EntityType[] $items
	 * @return EntityType[]
	 */
	private function applyOrder(array $items, ?OrderStructure $order): array
	{
		if ($order === null || $order->getItems() === [])
		{
			return $items;
		}

		usort(
			$items,
			function (EntityType $left, EntityType $right) use ($order): int {
				foreach ($order->getItems() as $orderItem)
				{
					$result = $this->getItemFieldValue($left, $orderItem->getProperty())
						<=> $this->getItemFieldValue($right, $orderItem->getProperty());
					if ($result !== 0)
					{
						return $orderItem->getOrder() === Order::Desc ? -$result : $result;
					}
				}

				return 0;
			},
		);

		return $items;
	}

	/**
	 * @param EntityType[] $items
	 * @return EntityType[]
	 */
	private function applyPagination(array $items, ?PaginationStructure $pagination): array
	{
		if ($pagination === null)
		{
			return $items;
		}
		if ($pagination->getLimit() <= 0)
		{
			throw new InvalidPaginationException(['limit' => $pagination->getLimit()]);
		}
		if ($pagination->getOffset() < 0)
		{
			throw new InvalidPaginationException(['offset' => $pagination->getOffset()]);
		}

		return array_slice($items, $pagination->getOffset(), $pagination->getLimit());
	}

	private function getItemFieldValue(EntityType $item, string $field): int|string
	{
		return match ($field)
		{
			'id' => $item->getId(),
			'code' => $item->getCode(),
			'title' => $item->getTitle(),
		};
	}
}

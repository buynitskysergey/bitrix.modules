<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Product;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListOrderStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListPaginationStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\RequestSelectedFields;
use Bitrix\Crm\V2\Internal\Entity\Product\ProductCard;
use Bitrix\Main\ORM\Query\Filter\Condition as OrmCondition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\Condition as RestCondition;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\Expression;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;

/**
 * Translates the parts of a list request into the read parameters of
 * {@see \Bitrix\Crm\V2\Internal\Repository\Product\ProductRepository::findAll()}.
 *
 * Nothing is renamed on the way: the property names of
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Product\ProductDto} are the field names of
 * {@see ProductCard} one to one, so a condition keeps the name the client wrote and only the shape of the
 * tree changes. What the standard filter of REST v3 can express and a card cannot - an expression as an
 * operand - is refused here rather than passed on: the read answers for a closed list of columns, and an
 * expression reaching it would be a failure of the portal instead of an answer to the client.
 *
 * Two things this mapper deliberately does **not** do:
 *
 * - it does not append a tie-breaker to the ordering. The primary key settles whatever an ordering leaves
 *   equal inside {@see \Bitrix\Crm\V2\Internal\Integration\IBlock\ProductCardSource}, an empty ordering
 *   included, and a second place to keep in step would only be a place to fall out of step;
 * - it does not decide what a card looks like in the answer. {@see mapSelect()} says which fields were
 *   asked for, and the same list serves twice: the read loads those fields and {@see ProductDtoMapper}
 *   answers them.
 */
final class ProductListRequestMapper
{
	/** @see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Product\ProductDto::$catalogId */
	private const CATALOG_ID_FIELD = ProductCard::FIELD_CATALOG_ID;

	/**
	 * @throws InvalidFilterException an operand a card cannot be filtered by.
	 */
	public function mapFilter(?FilterStructure $filter): ?ConditionTree
	{
		return $filter === null ? null : self::mapGroup($filter);
	}

	/**
	 * The catalog the read is about, as the client chose it.
	 *
	 * `catalogId` chooses a catalog rather than narrows a read of the default one, so the condition over it
	 * has to be a plain equality every other condition of the filter is `and`-ed with: only then is it the
	 * same thing as reading that catalog. Anything else over this field - an `in`, an inequality, a second
	 * condition, a place under an `or` or under a negation - is refused here. It is refused rather than
	 * passed on because the read always carries a predicate over one catalog: a condition the choice cannot
	 * express would silently answer an empty page, which is the one answer a client cannot tell from "there
	 * is nothing there".
	 *
	 * The condition itself is left in the filter and reaches the read as any other does. The choice is an
	 * addition to it, not a replacement: the page is what the whole filter says even if this reading of it
	 * is ever changed.
	 *
	 * @return int|null the catalog to read, or `null` when the client named none - the default one of the
	 *         CRM. Whether the named catalog is a catalog of the CRM at all is not decided here.
	 * @throws InvalidFilterException a condition over `catalogId` that is not a choice of one catalog.
	 */
	public function mapCatalogId(?FilterStructure $filter): ?int
	{
		if ($filter === null)
		{
			return null;
		}

		$conditions = self::collectCatalogConditions($filter, true);
		if ($conditions === [])
		{
			return null;
		}

		[$condition, $isConjunctive] = $conditions[0];
		$value = $condition->getRightOperand();
		if (
			count($conditions) > 1
			|| !$isConjunctive
			|| $condition->getOperator() !== Operator::Equal
			|| !is_int($value)
		)
		{
			throw new InvalidFilterException($condition);
		}

		return $value;
	}

	/**
	 * @return array<string, string> {@see ProductCard} field name => `ASC`/`DESC`; empty when the client
	 *         asked for no order of its own.
	 */
	public function mapOrder(?ItemListOrderStructure $order): array
	{
		return $order?->getList() ?? [];
	}

	/**
	 * The fields of a card the request is about, by the rule the whole REST of the CRM reads by. The
	 * identifier is what a card falls back to when nothing was asked for.
	 *
	 * @return string[] {@see ProductCard} field names.
	 */
	public function mapSelect(Request $request): array
	{
		return RequestSelectedFields::resolve($request, ProductCard::FIELD_ID);
	}

	/**
	 * The framework caps the page from above and refuses a zero size, but lets a negative one through; the
	 * lower bound is checked here, as it is for the list of product rows. The default page of the standard
	 * pagination is used when the client asked for none - the read is never left unbounded.
	 *
	 * @throws InvalidPaginationException
	 */
	public function mapPager(?ItemListPaginationStructure $pagination): Pager
	{
		if ($pagination === null)
		{
			return new Pager();
		}

		$limit = $pagination->getLimit();
		$offset = $pagination->getOffset();
		if ($limit <= 0)
		{
			throw new InvalidPaginationException(['limit' => $limit]);
		}
		if ($offset < 0)
		{
			throw new InvalidPaginationException(['offset' => $offset]);
		}

		return new Pager($limit, $offset);
	}

	/**
	 * Every condition over `catalogId` the filter holds, wherever in it they sit, each with the answer to
	 * the only question that matters about its place: whether the whole filter is `and`-ed with it. A group
	 * that is negative or joined by `or` breaks that for everything below it - the transport itself wraps a
	 * list of conditions into a group of its own, so the depth of a condition says nothing on its own.
	 *
	 * @return array<int, array{RestCondition, bool}>
	 */
	private static function collectCatalogConditions(FilterStructure $filter, bool $isConjunctive): array
	{
		$isConjunctive = $isConjunctive && !$filter->isNegative() && $filter->logic() === Logic::And;

		$found = [];
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$found = array_merge($found, self::collectCatalogConditions($condition, $isConjunctive));
			}
			elseif ($condition instanceof RestCondition && $condition->getLeftOperand() === self::CATALOG_ID_FIELD)
			{
				$found[] = [$condition, $isConjunctive];
			}
		}

		return $found;
	}

	/**
	 * @throws InvalidFilterException
	 */
	private static function mapGroup(FilterStructure $filter): ConditionTree
	{
		$tree = (new ConditionTree())
			->logic($filter->logic()->value)
			->negative($filter->isNegative())
		;

		foreach ($filter->getConditions() as $condition)
		{
			$tree->addCondition(match (true)
			{
				$condition instanceof FilterStructure => self::mapGroup($condition),
				$condition instanceof RestCondition => self::mapCondition($condition),
				default => throw new InvalidFilterException($condition),
			});
		}

		return $tree;
	}

	/**
	 * @throws InvalidFilterException
	 */
	private static function mapCondition(RestCondition $condition): OrmCondition
	{
		$column = $condition->getLeftOperand();
		$operator = $condition->getOperator();
		$value = $condition->getRightOperand();
		if (!is_string($column) || $value instanceof Expression)
		{
			throw new InvalidFilterException([$column, $operator->value, $value]);
		}

		// An empty set matches nothing, and the ORM would write `IN ()` for it. Answered as a condition no
		// card meets - the identifiers of the catalog are positive.
		if ($operator === Operator::In && $value === [])
		{
			return new OrmCondition(ProductCard::FIELD_ID, Operator::Equal->value, 0);
		}

		return new OrmCondition($column, $operator->value, $value);
	}
}

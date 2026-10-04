<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\Validation\RequestFilterValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldsInRequestFilterPropertyException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Structure;

/**
 * The filter of {@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow\ListProductRowRequest}:
 * an owner, and optionally the rows or the products to keep.
 *
 * The set of conditions is closed, and closing it is the point. Rows are read within a single owner
 * ({@see \Bitrix\Crm\V2\Public\Provider\Item\ProductRow\Param\ProductRowFilter}), so a condition the read
 * cannot express is refused rather than dropped: an owner is required, three fields are filterable, and
 * only equality and `in` are understood. Grouping - `logic`, `negative`, nested `conditions` - is refused
 * too: an `or` of conditions has no reading behind it, and answering it as an `and` would answer a
 * different question than the one asked.
 *
 * The lists of identifiers are bounded from above by {@see RequestListLimit}, the bound every list of a
 * request of this group shares: the page bounds the answer, and nothing but this bounds the condition. Every
 * refusal of this structure is one of the filter, that bound included, so a client that branches on the code
 * of an answer sees one kind of problem here and not two.
 *
 * ### Why the requirement of the owner is written here
 *
 * `#[FilterRequired]` cannot be used on this group. The attribute is honoured by
 * {@see Request::create()} only for a property that holds a {@see FilterStructure}, that class is `final`,
 * and its `getSimpleFilterConditions()` sees through exactly one level of nesting. Every route of the
 * group carries a trusted `entityTypeId`, so the body travels through
 * `CRestApiServer::applyBodyOverridesToQueryParams()`, which wraps the filter of the body into a list of
 * its own - one level more than that method can see through. The attribute would therefore hold for a
 * bare `["ownerId", "=", 1]` and fail for `[["ownerId", "=", 1], ["id", "in", [...]]]`, which is the very
 * shape the contract publishes. The requirement is checked here instead, and answered with the exception
 * the attribute itself raises, so the client sees no difference.
 *
 * The extra level of the transport needs no undoing: a node whose first element is an array is read as a
 * list of nodes, and the wrapper is one of those.
 */
final class ProductRowListFilterStructure extends Structure
{
	/** @see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow\ListProductRowRequest::$filter */
	private const REQUEST_PROPERTY = 'filter';

	private const FIELD_OWNER_ID = 'ownerId';
	private const FIELD_ID = 'id';
	private const FIELD_PRODUCT_ID = 'productId';

	/** The transport adds one level, a client list of conditions another, a condition is the third. */
	private const MAX_DEPTH = 4;

	private ?int $ownerId = null;

	/** @var int[]|null */
	private ?array $ids = null;

	/** @var int[]|null */
	private ?array $productIds = null;

	private function __construct()
	{
	}

	/**
	 * @throws InvalidFilterException
	 * @throws RequiredFieldsInRequestFilterPropertyException
	 * @throws RequestFilterValidationException a list of identifiers longer than {@see RequestListLimit}
	 */
	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		if (!is_array($value))
		{
			throw new InvalidFilterException($value);
		}

		if (self::getDto($dtoClass) === null)
		{
			self::addDto($dtoClass::create());
		}

		$structure = new self();
		$structure->readNode($value, $dtoClass, $request, 1);

		if ($structure->ownerId === null)
		{
			throw new RequiredFieldsInRequestFilterPropertyException(
				self::REQUEST_PROPERTY,
				[self::FIELD_OWNER_ID],
			);
		}

		return $structure;
	}

	/** Always positive: {@see create()} refuses a filter without an owner. */
	public function getOwnerId(): int
	{
		return $this->ownerId ?? 0;
	}

	/**
	 * @return int[]|null `null` - no restriction; an empty list matches no row.
	 */
	public function getIds(): ?array
	{
		return $this->ids;
	}

	/**
	 * @return int[]|null `null` - no restriction; an empty list matches no row.
	 */
	public function getProductIds(): ?array
	{
		return $this->productIds;
	}

	private function readNode(array $node, string $dtoClass, Request $request, int $depth): void
	{
		if ($node === [])
		{
			return;
		}

		if (!array_is_list($node))
		{
			throw new InvalidFilterException($node);
		}

		if (!is_array($node[0]))
		{
			$this->readCondition($node, $dtoClass, $request);

			return;
		}

		if ($depth >= self::MAX_DEPTH)
		{
			throw new InvalidFilterException($node);
		}

		foreach ($node as $child)
		{
			if (!is_array($child))
			{
				throw new InvalidFilterException($child);
			}

			$this->readNode($child, $dtoClass, $request, $depth + 1);
		}
	}

	private function readCondition(array $condition, string $dtoClass, Request $request): void
	{
		$normalized = self::normalizeCondition($condition, $dtoClass, $request);
		$fieldName = $normalized->getLeftOperand();

		if ($fieldName === self::FIELD_OWNER_ID)
		{
			self::denyRepeatedField($condition, $this->ownerId !== null);
			$this->ownerId = self::readOwnerId($condition, $normalized);

			return;
		}

		if ($fieldName === self::FIELD_ID)
		{
			self::denyRepeatedField($condition, $this->ids !== null);
			$this->ids = self::readIdentifiers($condition, $normalized, self::FIELD_ID);

			return;
		}

		if ($fieldName === self::FIELD_PRODUCT_ID)
		{
			self::denyRepeatedField($condition, $this->productIds !== null);
			$this->productIds = self::readIdentifiers($condition, $normalized, self::FIELD_PRODUCT_ID);

			return;
		}

		// Reachable only if the DTO gains a filterable field this structure does not read: an unknown
		// field and a field without #[Filterable] are refused by the framework before this point.
		throw new InvalidFilterException($condition);
	}

	/** One field, one condition: two conditions on one field are not an `and` this read can express. */
	private static function denyRepeatedField(array $condition, bool $isRepeated): void
	{
		if ($isRepeated)
		{
			throw new InvalidFilterException($condition);
		}
	}

	/**
	 * The framework does the checks that belong to the DTO: an unknown property, a property without
	 * `#[Filterable]`, an unknown operator and a value of the wrong type are all refused there.
	 */
	private static function normalizeCondition(array $condition, string $dtoClass, Request $request): Condition
	{
		$normalized = FilterStructure::create($condition, $dtoClass, $request)->getConditions()[0] ?? null;
		if (!$normalized instanceof Condition)
		{
			throw new InvalidFilterException($condition);
		}

		return $normalized;
	}

	private static function readOwnerId(array $condition, Condition $normalized): int
	{
		$value = $normalized->getRightOperand();
		if ($normalized->getOperator() !== Operator::Equal || !is_int($value) || $value <= 0)
		{
			throw new InvalidFilterException($condition);
		}

		return $value;
	}

	/**
	 * @param string $field the filterable field the identifiers were given for - the address the length of
	 *        the list is refused at.
	 * @return int[] An empty list is a filter that matches no row, not a malformed condition.
	 * @throws RequestFilterValidationException more identifiers than a request of this group may carry.
	 */
	private static function readIdentifiers(array $condition, Condition $normalized, string $field): array
	{
		$operator = $normalized->getOperator();
		$values = $normalized->getRightOperand();
		if ($operator === Operator::Equal)
		{
			$values = [$values];
		}
		elseif ($operator !== Operator::In || !is_array($values))
		{
			throw new InvalidFilterException($condition);
		}

		RequestListLimit::check(
			$values,
			self::REQUEST_PROPERTY . '.' . $field,
			RequestFilterValidationException::class,
		);

		foreach ($values as $value)
		{
			if (!is_int($value))
			{
				throw new InvalidFilterException($condition);
			}
		}

		return array_values($values);
	}
}

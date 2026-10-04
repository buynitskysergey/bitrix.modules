<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\ListRequisiteRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListOrderStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListPaginationStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\RequestSelectedFields;
use Bitrix\Crm\V2\Internal\Entity\Requisite\Requisite;
use Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteRepository;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\ORM\Query\Filter\Condition as OrmCondition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Exception\Validation\RequestFilterValidationException;
use Bitrix\Rest\V3\Structure\Filtering\Condition as RestCondition;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\Expression;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;

/**
 * Translates the parts of a list request into the read parameters of
 * {@see \Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteRepository::findAll()}.
 *
 * Nothing is renamed on the way: the property names of
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\RequisiteDto} are the field names of
 * {@see Requisite} one to one, so a condition keeps the name the client wrote and only the shape of the
 * tree changes. What the standard filter of REST v3 can express and a requisite cannot - an expression as
 * an operand - is refused here rather than passed on: the read answers for a closed list of columns, and an
 * expression reaching it would be a failure of the portal instead of an answer to the client.
 *
 * {@see Requisite} is an internal type and is used here as a transitional exception, the same one the
 * controller of the method carries: the dependency stays inside REST and promises no compatibility.
 *
 * Two things this mapper deliberately does **not** do:
 *
 * - it does not append a tie-breaker to the ordering. The primary key settles whatever an ordering leaves
 *   equal inside the read itself, an empty ordering included, and a second place to keep in step would only
 *   be a place to fall out of step;
 * - it does not decide what a requisite looks like in the answer. {@see mapSelect()} says which fields were
 *   asked for, and the same list serves twice: the read loads those fields and {@see RequisiteDtoMapper}
 *   answers them.
 */
final class RequisiteListRequestMapper
{
	/** Where an owner type is named, in the notation of the answer. */
	private const OWNER_TYPE_ID_FILTER_FIELD = 'filter.' . Requisite::FIELD_OWNER_TYPE_ID;

	/**
	 * The operators that ask for the owner types they name, rather than exclude or bound them, as long as
	 * no negation turns them around.
	 */
	private const OWNER_TYPE_REQUESTING_OPERATORS = [
		Operator::Equal,
		Operator::In,
	];

	/**
	 * @throws InvalidFilterException an operand a requisite cannot be filtered by.
	 * @throws RequestFilterValidationException an owner of a type the method does not serve.
	 */
	public function mapFilter(?FilterStructure $filter): ?ConditionTree
	{
		return $filter === null ? null : self::mapGroup($filter, false);
	}

	/**
	 * @return array<string, string> {@see Requisite} field name => `ASC`/`DESC`; empty when the client asked
	 *         for no order of its own.
	 */
	public function mapOrder(?ItemListOrderStructure $order): array
	{
		return $order?->getList() ?? [];
	}

	/**
	 * The fields of a requisite the request is about, by the rule the whole REST of the CRM reads by. The
	 * identifier is what a requisite falls back to when nothing was asked for, an empty list of fields
	 * included.
	 *
	 * @return string[] {@see Requisite} field names.
	 */
	public function mapSelect(ListRequisiteRequest $request): array
	{
		return RequestSelectedFields::resolve($request, Requisite::FIELD_ID);
	}

	/**
	 * The framework caps the page from above and refuses a zero size, but lets a negative one through; the
	 * lower bound is checked here, as it is for the list of catalog cards. The default page of the standard
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
	 * @param bool $isNegated whether the group of the caller sits under an odd number of negations. Carried
	 *        down because a condition under a negation asks for the opposite of what it names, which is what
	 *        {@see requireServedOwnerTypes()} decides by.
	 * @throws InvalidFilterException
	 * @throws RequestFilterValidationException
	 */
	private static function mapGroup(FilterStructure $filter, bool $isNegated): ConditionTree
	{
		$tree = (new ConditionTree())
			->logic($filter->logic()->value)
			->negative($filter->isNegative())
		;
		$isNegated = $isNegated !== $filter->isNegative();

		foreach ($filter->getConditions() as $condition)
		{
			$tree->addCondition(match (true)
			{
				$condition instanceof FilterStructure => self::mapGroup($condition, $isNegated),
				$condition instanceof RestCondition => self::mapCondition($condition, $isNegated),
				default => throw new InvalidFilterException(get_debug_type($condition)),
			});
		}

		return $tree;
	}

	/**
	 * @throws InvalidFilterException
	 * @throws RequestFilterValidationException
	 */
	private static function mapCondition(RestCondition $condition, bool $isNegated): OrmCondition
	{
		$column = $condition->getLeftOperand();
		$operator = $condition->getOperator();
		$value = $condition->getRightOperand();
		if (!is_string($column) || $value instanceof Expression)
		{
			throw new InvalidFilterException([$column, $operator->value, $value]);
		}

		// An empty set matches nothing, and the ORM would write `IN ()` for it. Answered as a condition no
		// requisite meets - the identifiers of the records are positive.
		if ($operator === Operator::In && $value === [])
		{
			return new OrmCondition(Requisite::FIELD_ID, Operator::Equal->value, 0);
		}

		if ($column === Requisite::FIELD_OWNER_TYPE_ID)
		{
			self::requireServedOwnerTypes($column, $operator, $value, $isNegated);
		}

		return new OrmCondition($column, $operator->value, $value);
	}

	/**
	 * A requisite of the portal belongs to a contact or to a company, and a filter that **asks for** any
	 * other owner asks for something the method does not serve: it is refused with the address the type came
	 * in rather than answered with an empty page, which is the one answer a client cannot tell from "there is
	 * nothing there".
	 *
	 * Only a condition that names the types it wants is judged, and a negation decides which one does that:
	 * an equality or an `in` that no negation turns around, and a `!=` that an odd number of them turns into
	 * an equality. A condition that excludes a type (a `!=` of its own, an equality or an `in` under a
	 * negation) asks for nothing unserved: the read `and`-s its own limit of owners onto whatever the client
	 * wrote, so such a filter answers with served owners either way and a refusal of it would be false.
	 *
	 * A condition that bounds a range of types (`between`, `>`, `<`) is not judged either, and for a weaker
	 * reason: `between [1, 2]` does ask for exactly the unserved ones and gets back the empty page a refusal
	 * is meant to replace. Refusing it would take a message that names a range where this one names a single
	 * type.
	 *
	 * @param string $column the field the condition names; a refusal of the operand quotes it, as the
	 *        refusal of a condition of any other field does.
	 * @param mixed $value the operand as the condition carries it: one type or a list of them.
	 * @param bool $isNegated whether a negation of the filter turns this condition around.
	 * @throws InvalidFilterException an owner type that is not a number at all.
	 * @throws RequestFilterValidationException
	 */
	private static function requireServedOwnerTypes(
		string $column,
		Operator $operator,
		mixed $value,
		bool $isNegated,
	): void
	{
		// Under an odd negation `!=` reads as an equality; nothing else turns into a request, as the
		// filter has no `not in` of its own - a negated group carries that meaning instead.
		$isRequestingOwnerTypes = $isNegated
			? $operator === Operator::NotEqual
			: in_array($operator, self::OWNER_TYPE_REQUESTING_OPERATORS, true)
		;
		if (!$isRequestingOwnerTypes)
		{
			return;
		}

		foreach (is_array($value) ? $value : [$value] as $ownerTypeId)
		{
			if (!is_int($ownerTypeId))
			{
				throw new InvalidFilterException([$column, $operator->value, $value]);
			}

			if (!in_array($ownerTypeId, RequisiteRepository::OWNER_TYPE_IDS, true))
			{
				throw new RequestFilterValidationException([
					new Error(
						new LocalizableMessage(
							'CRM_V2_REST_REQUISITE_OWNER_TYPE_IS_NOT_SERVED',
							['#OWNER_TYPE_ID#' => (string)$ownerTypeId],
						),
						self::OWNER_TYPE_ID_FILTER_FIELD,
					),
				]);
			}
		}
	}
}

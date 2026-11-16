<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Read;

use Bitrix\Main\Error;
use Bitrix\Note\Infrastructure\Rest\V3\Access\EntityState;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;

/**
 * Which states a list is asked for.
 *
 * State is an ordinary predicate of the filter, not a mode of the method: a caller names the states
 * the same way it names any other field, and naming none means the live ones - the answer a client
 * that knows nothing about archives and recycle bins expects.
 */
final class StateFilter
{
	public const FIELD = 'state';

	/**
	 * @return EntityState[] in canonical order: live, archived, trashed
	 *
	 * @throws RequestValidationException
	 */
	public static function parse(?FilterStructure $filter): array
	{
		$named = $filter === null ? [] : self::collect($filter);

		return $named === [] ? [EntityState::Live] : self::inCanonicalOrder($named);
	}

	/**
	 * The canon wraps every condition of the payload in a nested structure of its own, so the walk is
	 * recursive. getSimpleFilterConditions() would flatten it, but it keeps equality alone and `in` -
	 * the operator this filter exists for - would never arrive.
	 *
	 * Only the top-level conjunction is read. The states are not a predicate over rows but the
	 * choice of the streams the answer is assembled from, and a union is the only thing the walk can
	 * make of them: under `or` or under a negation the caller asks for a set the method cannot
	 * build, and answering it with that union would answer a different question.
	 *
	 * @param bool $conjunctive whether every node on the way here was a plain, non-negated `and`
	 *
	 * @return EntityState[]
	 *
	 * @throws RequestValidationException
	 */
	private static function collect(FilterStructure $filter, bool $conjunctive = true): array
	{
		$states = [];
		$inside = $conjunctive && !$filter->isNegative() && $filter->logic() === Logic::And;

		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$states = [...$states, ...self::collect($condition, $inside)];

				continue;
			}

			if ($condition instanceof Condition && $condition->getLeftOperand() === self::FIELD)
			{
				if (!$inside)
				{
					throw self::refuse(
						self::FIELD . ' belongs to the top-level "and" of the filter: it cannot sit'
						. ' under "or" or under a negation',
					);
				}

				$states = [...$states, ...self::readCondition($condition)];
			}
		}

		return $states;
	}

	/**
	 * @return EntityState[]
	 *
	 * @throws RequestValidationException
	 */
	private static function readCondition(Condition $condition): array
	{
		$values = match ($condition->getOperator())
		{
			Operator::Equal => [$condition->getRightOperand()],
			Operator::In => (array)$condition->getRightOperand(),
			default => throw self::refuse(
				'operator "' . $condition->getOperator()->value . '" is not supported for ' . self::FIELD
				. ': use "=" or "in"',
			),
		};

		return array_map(self::toState(...), $values);
	}

	/**
	 * @throws RequestValidationException
	 */
	private static function toState(mixed $value): EntityState
	{
		$state = $value instanceof EntityState
			? $value
			: (is_string($value) ? EntityState::tryFrom($value) : null);

		return $state ?? throw self::refuse(
			'unknown ' . self::FIELD . ': expected one of ' . implode(', ', self::known()),
		);
	}

	/**
	 * @param EntityState[] $named
	 *
	 * @return EntityState[]
	 */
	private static function inCanonicalOrder(array $named): array
	{
		return array_values(array_filter(
			EntityState::cases(),
			static fn(EntityState $state): bool => in_array($state, $named, true),
		));
	}

	/**
	 * @return string[]
	 */
	private static function known(): array
	{
		return array_map(static fn(EntityState $state): string => $state->value, EntityState::cases());
	}

	private static function refuse(string $message): RequestValidationException
	{
		return new RequestValidationException([new Error($message, self::FIELD)]);
	}
}

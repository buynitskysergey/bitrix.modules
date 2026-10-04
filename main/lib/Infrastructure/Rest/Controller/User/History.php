<?php

namespace Bitrix\Main\Infrastructure\Rest\Controller\User;

use Bitrix\Main\DB\Order;
use Bitrix\Main\Error;
use Bitrix\Main\Infrastructure\Rest\Dto\HistoryDto;
use Bitrix\Main\Infrastructure\Rest\Request\User\HistoryListRequest;
use Bitrix\Main\Infrastructure\Rest\Request\User\HistoryTailRequest;
use Bitrix\Main\Infrastructure\Rest\Support\LegacyQueryConverter;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserProfileHistoryTable;
use Bitrix\Main\UserProfileRecordTable;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Main\Infrastructure\Rest\Controller\AbstractController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\TailResponse;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\PaginationStructure;

#[DtoType(HistoryDto::class)]
class History extends AbstractController
{
	#[Scope('user')]
	public function listAction(HistoryListRequest $request): ListResponse
	{
		[, $queryFilter] = $this->buildAuthorizedHistoryFilter($request->filter);

		$order = LegacyQueryConverter::orderToLegacy($request->order);
		if ($order === [])
		{
			$order = ['ID' => 'DESC'];
		}

		$limit = $request->pagination?->getLimit() ?? PaginationStructure::DEFAULT_LIMIT;
		$offset = $request->pagination?->getOffset() ?? 0;

		$dbRes = UserProfileHistoryTable::getList([
			'filter' => $queryFilter,
			'limit' => $limit,
			'offset' => $offset,
			'order' => $order,
		]);

		$rawItems = [];
		while ($event = $dbRes->fetch())
		{
			/** @var \Bitrix\Main\Type\DateTime $ts */
			$ts = $event['DATE_INSERT'];
			$event['DATE_INSERT'] = \CRestUtil::convertDateTime($ts->toString());
			$rawItems[] = $event;
		}

		$selectedFields = $request->select?->getStructuredList() ?? [];
		$rawItems = $this->attachHistoryFieldValues($rawItems, $selectedFields);

		return new ListResponse($this->getDtoMapper()->mapCollection($rawItems, $selectedFields));
	}

	#[Scope('user')]
	public function tailAction(HistoryTailRequest $request): TailResponse
	{
		$cursor = $request->cursor;
		if ($cursor !== null && $cursor->getField() !== 'id')
		{
			throw new InvalidFilterException('Cursor field must be id.');
		}

		if ($request->filter !== null && in_array('id', $request->filter->getFields(), true))
		{
			throw new InvalidFilterException('Cursor field id cannot be used at filter.');
		}

		$order = $cursor?->getOrder()->value ?? Order::Asc->value;
		$limit = $cursor?->getLimit() ?? PaginationStructure::DEFAULT_LIMIT;
		if ($limit <= 0)
		{
			throw new RequestValidationException([
				new Error('Cursor limit must be a positive integer.', 'cursor'),
			]);
		}

		[, $queryFilter] = $this->buildAuthorizedHistoryFilter($request->filter);

		if ($cursor !== null)
		{
			$operator = $order === Order::Asc->value ? '>' : '<';
			$queryFilter = [
				'LOGIC' => 'AND',
				$queryFilter,
				[$operator . 'ID' => (int)$cursor->getValue()],
			];
		}

		$dbRes = UserProfileHistoryTable::getList([
			'filter' => $queryFilter,
			'limit' => $limit + 1,
			'order' => ['ID' => $order],
		]);

		$rawItems = [];
		while ($event = $dbRes->fetch())
		{
			/** @var \Bitrix\Main\Type\DateTime $ts */
			$ts = $event['DATE_INSERT'];
			$event['DATE_INSERT'] = \CRestUtil::convertDateTime($ts->toString());
			$rawItems[] = $event;
		}

		$hasMore = count($rawItems) > $limit;
		if ($hasMore)
		{
			array_pop($rawItems);
		}

		$lastRawItem = $rawItems !== [] ? $rawItems[array_key_last($rawItems)] : null;
		$selectedFields = $request->select?->getStructuredList() ?? [];
		$rawItems = $this->attachHistoryFieldValues($rawItems, $selectedFields);
		$collection = $this->getDtoMapper()->mapCollection($rawItems, $selectedFields);

		return new TailResponse(
			items: $collection,
			hasMore: $hasMore,
			cursorField: 'id',
			cursorValue: $lastRawItem !== null ? (string)$lastRawItem['ID'] : null,
		);
	}

	/**
	 * @return array{0: int, 1: array}
	 */
	private function buildAuthorizedHistoryFilter(?FilterStructure $filter): array
	{
		$this->assertSupportedHistoryFilter($filter);

		$simple = $filter->getSimpleFilterConditions();
		if (!array_key_exists('userId', $simple))
		{
			throw new RequestValidationException([new Error('USER_ID filter field is required.', 'filter')]);
		}

		$userId = (int)$simple['userId'];
		if ($userId <= 0)
		{
			throw new RequestValidationException([
				new Error('Exactly one userId equality filter is required.', 'filter'),
			]);
		}

		$this->assertCanAccessHistoryUser($userId);

		$queryFilter = ['=USER_ID' => $userId];

		if (array_key_exists('field', $simple))
		{
			$queryFilter['=\Bitrix\Main\UserProfileRecordTable:HISTORY.FIELD'] = $simple['field'];
		}

		$extraFilter = $this->buildExtraHistoryFilter($filter);
		if ($extraFilter === [])
		{
			return [$userId, $queryFilter];
		}

		return [
			$userId,
			[
				'LOGIC' => 'AND',
				$queryFilter,
				$extraFilter,
			],
		];
	}

	private function assertSupportedHistoryFilter(?FilterStructure $filter): void
	{
		if ($filter === null)
		{
			throw new RequestValidationException([new Error('USER_ID filter field is required.', 'filter')]);
		}

		$this->assertAndEqualityTree($filter);
	}

	private function assertAndEqualityTree(FilterStructure $filter): void
	{
		if ($filter->isNegative())
		{
			throw new RequestValidationException([
				new Error('Negative filters are not supported for history list.', 'filter'),
			]);
		}

		if ($filter->logic() === Logic::Or)
		{
			throw new RequestValidationException([
				new Error('OR filters are not supported for history list.', 'filter'),
			]);
		}

		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$this->assertAndEqualityTree($condition);
				continue;
			}

			if (!$condition instanceof Condition)
			{
				continue;
			}

			$field = $condition->getLeftOperand();
			$operator = $condition->getOperator();

			if ($field === 'userId' || $field === 'field')
			{
				if ($operator !== Operator::Equal)
				{
					throw new RequestValidationException([
						new Error("Only '=' operation is allowed for the filter field {$field}.", 'filter'),
					]);
				}
			}
		}
	}

	private function assertCanAccessHistoryUser(int $userId): void
	{
		global $USER;

		if (!$USER->CanDoOperation('edit_all_users') && $userId !== (int)$USER->GetID())
		{
			throw new AccessDeniedException();
		}
	}

	/**
	 * Builds ORM filter for non-ACL fields (dateInsert, eventType, …).
	 * userId/field are taken from getSimpleFilterConditions() separately.
	 */
	private function buildExtraHistoryFilter(FilterStructure $filter): array
	{
		$queryFilter = LegacyQueryConverter::filterToLegacy($filter);
		$queryFilter = $this->stripSimpleManagedPredicates($queryFilter);

		return $this->normalizeDateInsertFilter($queryFilter);
	}

	private function stripSimpleManagedPredicates(array $queryFilter): array
	{
		$result = [];
		foreach ($queryFilter as $key => $value)
		{
			if ($key === 'LOGIC')
			{
				$result[$key] = $value;
				continue;
			}

			if (is_int($key) && is_array($value))
			{
				$nested = $this->stripSimpleManagedPredicates($value);
				if ($nested !== [] && !(count($nested) === 1 && isset($nested['LOGIC'])))
				{
					$result[$key] = $nested;
				}
				continue;
			}

			if (!is_string($key))
			{
				$result[$key] = $value;
				continue;
			}

			$field = ltrim($key, '=!<>@%*');
			if (
				$field === 'USER_ID'
				|| $field === 'FIELD'
				|| str_contains($key, 'UserProfileRecordTable:HISTORY.FIELD')
			)
			{
				continue;
			}

			$result[$key] = $value;
		}

		if (isset($result['LOGIC']) && count($result) === 1)
		{
			return [];
		}

		if (
			isset($result['LOGIC'])
			&& ($result['LOGIC'] === 'AND' || $result['LOGIC'] === 'OR')
		)
		{
			$children = [];
			foreach ($result as $key => $value)
			{
				if ($key === 'LOGIC')
				{
					continue;
				}
				$children[] = $value;
			}
			if ($children === [])
			{
				return [];
			}
			if (count($children) === 1 && $result['LOGIC'] === 'AND')
			{
				return is_array($children[0]) ? $children[0] : [$children[0]];
			}
		}

		return $result;
	}

	private function normalizeDateInsertFilter(array $queryFilter): array
	{
		foreach ($queryFilter as $key => $value)
		{
			if ($key === 'LOGIC')
			{
				continue;
			}

			if (is_int($key) && is_array($value))
			{
				$queryFilter[$key] = $this->normalizeDateInsertFilter($value);
				continue;
			}

			if (!is_string($key))
			{
				continue;
			}

			$field = ltrim($key, '=!<>@%*');
			if ($field !== 'DATE_INSERT')
			{
				continue;
			}

			$queryFilter[$key] = $this->normalizeDateInsertValue($value);
		}

		return $queryFilter;
	}

	private function normalizeDateInsertValue(mixed $value): mixed
	{
		if ($value instanceof DateTime)
		{
			return $value;
		}

		if (is_array($value))
		{
			return array_map($this->normalizeDateInsertValue(...), $value);
		}

		if (!is_string($value) || $value === '')
		{
			return $value;
		}

		$phpDateTime = \DateTime::createFromFormat(DATE_ATOM, $value);
		if ($phpDateTime instanceof \DateTime)
		{
			return DateTime::createFromPhp($phpDateTime);
		}

		$legacyDate = \CRestUtil::unConvertDateTime($value);
		if (is_string($legacyDate) && $legacyDate !== '')
		{
			return DateTime::createFromUserTime($legacyDate);
		}

		throw new RequestValidationException([new Error('Invalid dateInsert filter value.', 'filter')]);
	}

	private function attachHistoryFieldValues(array $rawItems, array $selectedFields): array
	{
		$needField = in_array('field', $selectedFields, true) || array_key_exists('field', $selectedFields);
		if (!$needField || $rawItems === [])
		{
			return $rawItems;
		}

		$historyIds = array_values(array_filter(array_map(
			static fn(array $item): int => (int)($item['ID'] ?? 0),
			$rawItems,
		)));
		if ($historyIds === [])
		{
			return $rawItems;
		}

		$byHistory = [];
		$records = UserProfileRecordTable::getList([
			'filter' => ['@HISTORY_ID' => $historyIds],
			'select' => ['HISTORY_ID', 'FIELD'],
		]);
		while ($row = $records->fetch())
		{
			$historyId = (int)$row['HISTORY_ID'];
			if (!isset($byHistory[$historyId]))
			{
				$byHistory[$historyId] = $row['FIELD'];
			}
		}

		foreach ($rawItems as &$item)
		{
			$item['FIELD'] = $byHistory[(int)$item['ID']] ?? null;
		}
		unset($item);

		return $rawItems;
	}
}

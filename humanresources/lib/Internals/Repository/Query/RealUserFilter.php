<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Internals\Repository\Query;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UserTable;

/**
 * Single query-layer source of truth for telling a real (non-virtual) user apart.
 * A real user is a UserTable row with IS_REAL_USER = 'Y' (EXTERNAL_AUTH_ID is not one of
 * UserTable::getExternalUserTypes()); the expression is resolved by main, never duplicated here.
 * Member-oriented methods (applyToMemberQuery, memberCondition) require the caller to already
 * constrain the query to ENTITY_TYPE = USER.
 */
final class RealUserFilter
{
	public static function applyToMemberQuery(Query $query): Query
	{
		return $query->whereIn('ENTITY_ID', self::realUserSubQuery());
	}

	public static function memberCondition(string $entityIdField = 'ENTITY_ID'): ConditionTree
	{
		return (new ConditionTree())->whereIn($entityIdField, self::realUserSubQuery());
	}

	public static function applyToUserQuery(Query $query): Query
	{
		return $query->where('IS_REAL_USER', 'Y');
	}

	/**
	 * @param int[] $userIds
	 *
	 * @return int[]
	 */
	public static function filterRealUserIds(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$rows = UserTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $userIds)
			->where('IS_REAL_USER', 'Y')
			->fetchAll()
		;

		return array_values(
			array_map(static fn(array $row): int => (int)$row['ID'], $rows),
		);
	}

	/**
	 * Must be appended to any manual cache id that depends on the withVirtualUsers flag.
	 */
	public static function cacheKeySuffix(bool $withVirtualUsers): string
	{
		return '_with_virtual_' . ($withVirtualUsers ? 'Y' : 'N');
	}

	private static function realUserSubQuery(): Query
	{
		return UserTable::query()
			->setSelect(['ID'])
			->where('IS_REAL_USER', 'Y')
		;
	}
}

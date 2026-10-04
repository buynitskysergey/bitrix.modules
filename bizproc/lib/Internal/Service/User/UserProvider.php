<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\User;

/**
 * Users by id for name formatting: a page of rows formatted through
 * {@see \CBPHelper::usersArrayToString} costs one query instead of a query per cell.
 */
final class UserProvider
{
	private const FIELDS = ['ID', 'LOGIN', 'EMAIL', 'NAME', 'LAST_NAME', 'SECOND_NAME'];

	/** @var array<int, array|null> */
	private static array $users = [];

	/**
	 * Loads users in a single query, so that further {@see self::get()} calls for the given ids cost none.
	 *
	 * @param int[] $userIds
	 */
	public static function prefetch(array $userIds): void
	{
		$idsToLoad = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0 && !array_key_exists($userId, self::$users))
			{
				$idsToLoad[$userId] = $userId;
			}
		}

		if ($idsToLoad === [])
		{
			return;
		}

		$iterator = self::query(['ID' => implode('|', $idsToLoad)]);
		while ($user = $iterator->Fetch())
		{
			self::$users[(int)$user['ID']] = $user;
			unset($idsToLoad[(int)$user['ID']]);
		}

		foreach ($idsToLoad as $userId)
		{
			self::$users[$userId] = null;
		}
	}

	public static function get(int $userId): ?array
	{
		if (!array_key_exists($userId, self::$users))
		{
			self::$users[$userId] = self::query(['ID_EQUAL_EXACT' => $userId])->Fetch() ?: null;
		}

		return self::$users[$userId];
	}

	private static function query(array $filter): \CDBResult
	{
		return \CUser::GetList(
			'LAST_NAME',
			'asc',
			$filter,
			[
				'NAV_PARAMS' => false,
				'FIELDS' => self::FIELDS,
			],
		);
	}
}

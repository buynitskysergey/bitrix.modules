<?php

namespace Bitrix\Crm\Integration\Analytics;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\UserTable;

/**
 * Resolves the user id written into the user_id field of a CRM analytics event.
 *
 * Analytics counts active employees, so 0 is the reserved "actor unknown" value and anything
 * non-zero is read as a live employee. Without this resolver AnalyticsEvent falls back to
 * the ambient current user, which in server-side and public flows is not the initiator at all.
 *
 * An actor must be an active account with no external auth type. Deactivated accounts are
 * excluded on purpose: a robot configured by a since-fired employee keeps running for years,
 * and counting it as that employee's activity is the very distortion this resolver fixes.
 * Extranet users stay actors by decision: they are live people working in CRM.
 */
final class ActorResolver
{
	/** @var array<int, bool> */
	private static array $employeeCache = [];

	/**
	 * @param int|null $candidateId Initiator known to the caller; null falls back to the CRM context.
	 *
	 * @return int Employee id, or 0 when the actor is unknown or is not an employee.
	 */
	public static function resolve(?int $candidateId = null): int
	{
		$actorId = $candidateId ?? self::getContextUserId();
		if ($actorId <= 0)
		{
			return 0;
		}

		return self::isEmployee($actorId) ? $actorId : 0;
	}

	public static function clearCache(): void
	{
		self::$employeeCache = [];
	}

	private static function getContextUserId(): int
	{
		try
		{
			return Container::getInstance()->getContext()->getUserId();
		}
		catch (\Throwable)
		{
			return 0;
		}
	}

	private static function isEmployee(int $userId): bool
	{
		self::$employeeCache[$userId] ??= self::fetchIsEmployee($userId);

		return self::$employeeCache[$userId];
	}

	private static function fetchIsEmployee(int $userId): bool
	{
		try
		{
			$user = UserTable::getList([
				'select' => ['IS_REAL_USER'],
				'filter' => ['=ID' => $userId, '=ACTIVE' => 'Y'],
				'limit' => 1,
			])->fetch();
		}
		catch (\Throwable)
		{
			// analytics must never break the operation it describes
			return false;
		}

		if (!is_array($user))
		{
			return false;
		}

		// IS_REAL_USER is an expression field declared with a boolean value type, so it may arrive
		// either as the raw 'Y'/'N' or as a converted bool. Treating a converted true as "not an
		// employee" would silently zero out every actor.
		return $user['IS_REAL_USER'] === true || $user['IS_REAL_USER'] === 'Y';
	}
}

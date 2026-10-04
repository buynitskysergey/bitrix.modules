<?php

namespace Bitrix\Crm\Activity;

use Bitrix\Crm\Integration\VoxImplantManager;
use Bitrix\Crm\Service\Container;
use CCrmActivity;

final class CallDeletionRestriction
{
	/**
	 * Whether the given user is forbidden to delete the specified activity.
	 *
	 * Restricts deletion only for telephony (voximplant) call activities and only for users
	 * who are not admins of at least one of the activity's owner entities. System/anonymous
	 * contexts ($userId <= 0) are never restricted.
	 *
	 * The restriction is lifted via UserPermissions::isAdminForEntity(). For the standard CRM
	 * entities that telephony calls are bound to (deal/lead/contact/company) this is equivalent
	 * to isCrmAdmin(). The broader semantics - for a dynamic type inside an automated solution the
	 * restriction may also be lifted by that solution's admin - are intentional.
	 *
	 * @param int $activityId
	 * @param array $activityFields Fields as returned by CCrmActivity::GetByID; the array itself does
	 *   not carry owner bindings, which this method loads separately via CCrmActivity::GetBindings().
	 * @param int|null $userId
	 * @return bool
	 */
	public static function isDeletionRestricted(int $activityId, array $activityFields, ?int $userId): bool
	{
		if ($userId === null || $userId <= 0)
		{
			return false;
		}

		if (!VoxImplantManager::isActivityBelongsToVoximplant($activityFields))
		{
			return false;
		}

		$userPermissions = Container::getInstance()->getUserPermissions($userId);

		$owners = CCrmActivity::GetBindings($activityId);
		if (!is_array($owners) || empty($owners))
		{
			$owners = [
				[
					'OWNER_TYPE_ID' => $activityFields['OWNER_TYPE_ID'] ?? null,
					'OWNER_ID' => $activityFields['OWNER_ID'] ?? null,
				],
			];
		}

		foreach ($owners as $owner)
		{
			$ownerTypeId = (int)($owner['OWNER_TYPE_ID'] ?? 0);
			if ($userPermissions->isAdminForEntity($ownerTypeId, null))
			{
				return false;
			}
		}

		return true;
	}
}

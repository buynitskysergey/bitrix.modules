<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service;

/**
 * Resolves the portal system user used as the default author of automated or
 * authorless CRM history records: the first active member of the Administrators
 * group (group 1), lowest id.
 *
 * Falls back to the portal founder (user 1) when no active administrator
 * exists, so a valid author id is always returned. Single source of truth for
 * the crm module — Timeline\Controller and EventHistory delegate here.
 */
final class SystemUser
{
	public const ADMIN_GROUP_ID = 1;

	private const FALLBACK_AUTHOR_ID = 1;

	private static ?int $defaultAuthorId = null;

	public static function getDefaultAuthorId(): int
	{
		if (self::$defaultAuthorId === null)
		{
			$user = \CUser::GetList(
				'ID',
				'ASC',
				['GROUPS_ID' => [self::ADMIN_GROUP_ID], 'ACTIVE' => 'Y'],
				['FIELDS' => ['ID'], 'NAV_PARAMS' => ['nTopCount' => 1]]
			)->fetch();

			$userId = is_array($user) ? (int)$user['ID'] : 0;
			self::$defaultAuthorId = ($userId > 0 ? $userId : self::FALLBACK_AUTHOR_ID);
		}

		return self::$defaultAuthorId;
	}
}

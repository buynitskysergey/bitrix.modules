<?php

namespace Bitrix\Calendar\View;

use Bitrix\Main\Localization\Loc;

final class EventViewData
{
	private const RESTRICTED_ENTRY_FIELDS = [
		'ID',
		'NAME',
		'DATE_FROM',
		'DATE_TO',
		'DT_SKIP_TIME',
		'DT_LENGTH',
		'DURATION',
		'TZ_FROM',
		'TZ_TO',
		'TZ_OFFSET_FROM',
		'TZ_OFFSET_TO',
		'~USER_OFFSET_FROM',
		'~USER_OFFSET_TO',
		'SECTION_ID',
		'SECT_ID',
		'CAL_TYPE',
		'ACCESSIBILITY',
	];

	public static function prepare(
		array $entry,
		array $section,
		array $permissions,
		array $userIndex,
		int $userId,
	): array
	{
		if (self::canReadFullEntry($entry, $permissions, $userId))
		{
			return [
				'entry' => $entry,
				'section' => $section,
				'userIndex' => self::getEntryUserIndex($entry, $userIndex),
				'restricted' => false,
			];
		}

		$restrictedPermissions = [
			'view_time' => !empty($permissions['view_time']),
			'view_title' => !self::isPrivatePersonalEntry($entry) && !empty($permissions['view_title']),
			'view_full' => false,
			'view_comments' => false,
			'edit' => false,
			'editLocation' => false,
			'editAttendees' => false,
			'delete' => false,
		];

		$restrictedEntry = array_intersect_key($entry, array_flip(self::RESTRICTED_ENTRY_FIELDS));
		if (!$restrictedPermissions['view_title'])
		{
			$restrictedEntry['NAME'] = self::getBusyMaskedName($entry);
		}

		return [
			'entry' => array_merge(
				$restrictedEntry,
				[
					'OWNER_ID' => 0,
					'CREATED_BY' => 0,
					'MEETING_HOST' => 0,
					'PARENT_ID' => $entry['ID'],
					'IS_MEETING' => false,
					'PRIVATE_EVENT' => false,
					'IMPORTANCE' => 'normal',
					'MEETING_STATUS' => '',
					'ATTENDEE_LIST' => [],
					'ATTENDEES_CODES' => [],
					'attendeesEntityList' => [],
					'REMIND' => [],
					'MEETING' => new \stdClass(),
					'RELATIONS' => new \stdClass(),
					'DESCRIPTION' => '',
					'~DESCRIPTION' => '',
					'LOCATION' => '',
					'UF_CRM_CAL_EVENT' => null,
					'UF_WEBDAV_CAL_EVENT' => null,
					'RRULE' => false,
					'RECURRENCE_ID' => 0,
					'ORIGINAL_RECURSION_ID' => 0,
					'EXDATE' => '',
					'permissions' => $restrictedPermissions,
					'IS_ACCESSIBLE_TO_USER' => false,
				],
			),
			'section' => [
				'ID' => $section['ID'],
				'CAL_TYPE' => $section['CAL_TYPE'],
				'OWNER_ID' => 0,
				'NAME' => '',
				'COLOR' => '',
				// The section keeps the key set of a section, not the one of an event: a consumer
				// asks it for section actions, and a missing key answers neither yes nor no.
				'PERM' => [
					'view_time' => $restrictedPermissions['view_time'],
					'view_title' => $restrictedPermissions['view_title'],
					'view_full' => false,
					'add' => false,
					'edit' => false,
					'edit_section' => false,
					'access' => false,
				],
			],
			'userIndex' => [],
			'restricted' => true,
		];
	}

	public static function canReadFullEntry(array $entry, array $permissions, int $userId): bool
	{
		if (($entry['IS_ACCESSIBLE_TO_USER'] ?? null) === false)
		{
			return false;
		}

		if (self::isOwnEntry($entry, $userId))
		{
			return true;
		}

		return !self::isPrivatePersonalEntry($entry) && !empty($permissions['view_full']);
	}

	/**
	 * Tells the entries the user reads by his own relation to them: his authorship, his personal
	 * calendar or his attendance. The verdict costs no access check, so a caller may ask it first
	 * and compute the rights only for what is left.
	 */
	public static function isOwnEntry(array $entry, int $userId): bool
	{
		if ($userId <= 0 || ($entry['IS_ACCESSIBLE_TO_USER'] ?? null) === false)
		{
			return false;
		}

		if (
			$userId === (int)($entry['CREATED_BY'] ?? 0)
			|| (($entry['CAL_TYPE'] ?? null) === 'user' && $userId === (int)($entry['OWNER_ID'] ?? 0))
		)
		{
			return true;
		}

		if (!empty($entry['IS_MEETING']))
		{
			foreach ($entry['ATTENDEE_LIST'] ?? [] as $attendee)
			{
				if ((int)$attendee['id'] === $userId)
				{
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The title of an entry the user may not read is replaced by its availability, the same way the
	 * reader of the list does it - so the class holds the rule itself and does not rely on the caller.
	 */
	private static function getBusyMaskedName(array $entry): string
	{
		$accessibility = mb_strtoupper((string)($entry['ACCESSIBILITY'] ?? '')) ?: 'BUSY';

		return '[' . Loc::getMessage('EC_ACCESSIBILITY_' . $accessibility) . ']';
	}

	private static function isPrivatePersonalEntry(array $entry): bool
	{
		return ($entry['CAL_TYPE'] ?? null) === 'user' && !empty($entry['PRIVATE_EVENT']);
	}

	private static function getEntryUserIndex(array $entry, array $userIndex): array
	{
		$userIds = [
			(int)($entry['CREATED_BY'] ?? 0),
			(int)($entry['MEETING_HOST'] ?? 0),
			(int)($entry['MEETING']['MEETING_CREATOR'] ?? 0),
		];
		if (($entry['CAL_TYPE'] ?? null) === 'user')
		{
			$userIds[] = (int)($entry['OWNER_ID'] ?? 0);
		}
		foreach ($entry['ATTENDEE_LIST'] ?? [] as $attendee)
		{
			$userIds[] = (int)$attendee['id'];
		}

		$userIds = array_filter($userIds, static fn (int $id): bool => $id > 0);

		return array_intersect_key($userIndex, array_flip($userIds));
	}
}

<?php

namespace Bitrix\Crm\Activity\ToDo;

use Bitrix\Main\Type\DateTime;

/**
 * Maps CRM activity fields to calendar-event fields used for meeting-room occupancy checks
 * (Bitrix\Calendar\Rooms\AccessibilityManager::checkAccessibility).
 */
final class CalendarEventOccupancyFields
{
	private function __construct(
		private readonly string $location,
		private readonly array $fields,
	)
	{
	}

	public static function build(array $activityFields, int $responsibleId, ?int $calendarEventId = null): ?self
	{
		$location = self::normalizeLocation((string)($activityFields['LOCATION'] ?? ''));
		if ($location === '')
		{
			return null;
		}

		$timeFields = self::buildEventTimeFields($activityFields, $responsibleId);
		if (!isset($timeFields['DATE_FROM'], $timeFields['DATE_TO'], $timeFields['TZ_FROM']))
		{
			return null;
		}

		$fields = $timeFields + [
			'ID' => (int)$calendarEventId,
			'SKIP_TIME' => false,
		];

		return new self($location, $fields);
	}

	/**
	 * Drops any reservation event id from the client location suffix
	 * (calendar_<roomId>_<eventId> / ECMR_<mrId>_<eventId>). AccessibilityManager treats the third
	 * segment as an event to exclude from the conflict check, so a caller could pass the id of a
	 * conflicting booking and hide it. The current booking is excluded through the trusted
	 * $calendarEventId (Result field ID); the untrusted suffix must not carry exclusion power.
	 */
	private static function normalizeLocation(string $location): string
	{
		if ($location === '')
		{
			return '';
		}

		$parsed = \Bitrix\Calendar\Rooms\Util::parseLocation($location);
		if ($parsed['room_id'])
		{
			return 'calendar_' . $parsed['room_id'];
		}
		if ($parsed['mrid'])
		{
			return 'ECMR_' . $parsed['mrid'];
		}

		return $location;
	}

	/**
	 * Converts activity START_TIME/END_TIME (current user time) to calendar owner time fields.
	 * Kept identical to CCrmActivity::SaveCalendarEvent conversion, including per-field isset checks.
	 */
	public static function buildEventTimeFields(array $activityFields, int $responsibleId): array
	{
		$userTzName = \CCalendar::GetUserTimezoneName($responsibleId, true);
		if (!$userTzName)
		{
			return [];
		}

		$userTz = new \DateTimeZone($userTzName);
		$format = DateTime::getFormat();

		$fields = [];
		if (isset($activityFields['START_TIME']))
		{
			$startTime = DateTime::createFromUserTime($activityFields['START_TIME']);
			$startTime->setTimeZone($userTz);
			$fields['DATE_FROM'] = $startTime->format($format);
			$fields['TZ_FROM'] = $userTzName;
		}
		if (isset($activityFields['END_TIME']))
		{
			$endTime = DateTime::createFromUserTime($activityFields['END_TIME']);
			$endTime->setTimeZone($userTz);
			$fields['DATE_TO'] = $endTime->format($format);
			$fields['TZ_TO'] = $userTzName;
		}

		return $fields;
	}

	public function getLocation(): string
	{
		return $this->location;
	}

	public function getFields(): array
	{
		return $this->fields;
	}
}

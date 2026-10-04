<?php

namespace Bitrix\Crm\Activity\Entity;

use Bitrix\Crm\Activity\Provider;
use Bitrix\Crm\Activity\ToDo\CalendarEventOccupancyFields;
use Bitrix\Calendar\Rooms\AccessibilityManager;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

class ToDo extends BaseActivity
{
	public function isValidProviderId(string $providerId): bool
	{
		return $this->provider::getId() === Provider\ToDo\ToDo::getId() && $providerId === Provider\ToDo\ToDo::getId();
	}

	public function getProviderId(): string
	{
		return Provider\ToDo\ToDo::PROVIDER_ID;
	}

	public function getProviderTypeId(): string
	{
		return Provider\ToDo\ToDo::PROVIDER_TYPE_ID_DEFAULT;
	}

	public function save(array $options = [], $useCurrentSettings = false): Result
	{
		$this->tryAppendTags();
		$this->appendContextToOptions($options);

		$occupancyResult = $this->checkLocationOccupancy($options);
		if (!$occupancyResult->isSuccess())
		{
			return $occupancyResult;
		}

		return parent::save($options, $useCurrentSettings);
	}

	private function checkLocationOccupancy(array $options): Result
	{
		$result = new Result();

		if (!Loader::includeModule('calendar'))
		{
			return $result;
		}

		$additionalFields = $this->getAdditionalFields();
		if ((string)($additionalFields['LOCATION'] ?? '') === '')
		{
			return $result;
		}

		// Guard only saves that actually create or update the room booking. When the calendar event
		// is skipped (e.g. no available section) no reservation is written, so blocking here would be
		// a false positive.
		$activityFields = $additionalFields;
		$activityFields['CALENDAR_EVENT_ID'] = $this->getCalendarEventId();
		if (Provider\ToDo\ToDo::skipCalendarSync($activityFields, $options))
		{
			return $result;
		}

		$calendarEventId = $this->resolveCalendarEventId();
		$occupancyFields = CalendarEventOccupancyFields::build(
			$additionalFields,
			(int)$this->getResponsibleId(),
			$calendarEventId,
		);
		if ($occupancyFields === null)
		{
			return $result;
		}

		// Metadata-only edits (color/description/files) re-enrich the entity with the stored booking's
		// unchanged room and interval, then save. Running the occupancy query on every such save is
		// pure overhead: only creating a booking or moving its room/interval can introduce a conflict.
		if ($calendarEventId && $this->isSameAsStoredBooking($occupancyFields, (int)$calendarEventId))
		{
			return $result;
		}

		$isFree = AccessibilityManager::checkAccessibility(
			$occupancyFields->getLocation(),
			['fields' => $occupancyFields->getFields()],
		);
		if (!$isFree)
		{
			$result->addError(
				new Error(
					Loc::getMessage('CRM_TODO_ENTITY_ACTIVITY_LOCATION_BUSY'),
					'LOCATION_BUSY',
				),
			);
		}

		return $result;
	}

	private function resolveCalendarEventId(): ?int
	{
		if ($this->getCalendarEventId())
		{
			return $this->getCalendarEventId();
		}

		if ($this->getId())
		{
			$existedActivity = \CCrmActivity::GetByID($this->getId());
			if ($existedActivity && isset($existedActivity['CALENDAR_EVENT_ID']))
			{
				return (int)$existedActivity['CALENDAR_EVENT_ID'];
			}
		}

		return null;
	}

	/**
	 * Returns true when the requested room and interval match the already-saved calendar event, so
	 * the save cannot introduce a new overlap and the occupancy query can be skipped. Fails safe:
	 * any missing/uncomparable field means "not the same" and the guard runs.
	 */
	private function isSameAsStoredBooking(CalendarEventOccupancyFields $occupancyFields, int $calendarEventId): bool
	{
		$storedEvent = \Bitrix\Crm\Integration\Calendar::getEvent($calendarEventId, true);
		if (!is_array($storedEvent))
		{
			return false;
		}

		$requestedRoom = \Bitrix\Calendar\Rooms\Util::parseLocation($occupancyFields->getLocation());
		$storedRoom = \Bitrix\Calendar\Rooms\Util::parseLocation((string)($storedEvent['LOCATION'] ?? ''));
		if (
			(int)$requestedRoom['room_id'] !== (int)$storedRoom['room_id']
			|| (int)$requestedRoom['mrid'] !== (int)$storedRoom['mrid']
		)
		{
			return false;
		}

		$requestedFields = $occupancyFields->getFields();
		$requestedInterval = $this->bookingIntervalUtc(
			$requestedFields['DATE_FROM'] ?? null,
			$requestedFields['DATE_TO'] ?? null,
			$requestedFields['TZ_FROM'] ?? null,
		);
		$storedInterval = $this->bookingIntervalUtc(
			$storedEvent['DATE_FROM'] ?? null,
			$storedEvent['DATE_TO'] ?? null,
			$storedEvent['TZ_FROM'] ?? null,
		);

		return $requestedInterval !== null && $requestedInterval === $storedInterval;
	}

	/**
	 * @return array{0: int, 1: int}|null UTC start/end timestamps, or null when the interval is incomplete.
	 */
	private function bookingIntervalUtc(?string $dateFrom, ?string $dateTo, ?string $timezone): ?array
	{
		if (($dateFrom ?? '') === '' || ($dateTo ?? '') === '' || ($timezone ?? '') === '')
		{
			return null;
		}

		return [
			\Bitrix\Calendar\Util::getDateTimestampUtc(new DateTime($dateFrom), $timezone),
			\Bitrix\Calendar\Util::getDateTimestampUtc(new DateTime($dateTo), $timezone),
		];
	}

	private function tryAppendTags(): void
	{
		$additionalFields = $this->getAdditionalFields();

		if (
			empty($additionalFields['START_TIME'])
			|| empty($additionalFields['END_TIME'])
			|| empty($additionalFields['SETTINGS']['USERS'])
		)
		{
			return;
		}

		$calendarEventId = $this->resolveCalendarEventId();

		$userIds = $additionalFields['SETTINGS']['USERS'];
		$fromTimestampEvent = DateTime::createFromUserTime($additionalFields['START_TIME'])->getTimestamp();
		$toTimestampEvent = DateTime::createFromUserTime($additionalFields['END_TIME'])->getTimestamp();

		if ($this->hasOverlapEvent($userIds, $fromTimestampEvent, $toTimestampEvent, $calendarEventId))
		{
			$additionalFields['SETTINGS']['TAGS'] = ['OVERLAP_EVENT' => true];
			$this->setAdditionalFields($additionalFields);
		}
	}

	private function hasOverlapEvent(
		array $userIds,
		int   $fromTimestampEvent,
		int   $toTimestampEvent,
		?int $currentCalendarEventId = null
	): bool
	{
		$busyUsersIds = \Bitrix\Crm\Integration\Calendar::getBusyUsersIds(
			$userIds,
			$fromTimestampEvent,
			$toTimestampEvent,
			$currentCalendarEventId
		);

		return !empty($busyUsersIds);
	}

	private function appendContextToOptions(array &$options): void
	{
		$options['CONTEXT'] = $this->getContext();
	}
}

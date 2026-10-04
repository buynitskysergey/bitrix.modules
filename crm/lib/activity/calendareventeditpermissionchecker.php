<?php

namespace Bitrix\Crm\Activity;

use Bitrix\Calendar\Access\ActionDictionary;
use Bitrix\Calendar\Access\EventAccessController;
use Bitrix\Main\Loader;
use CCalendarEvent;

/**
 * Stateless predicate: may the given user change the deadline of a calendar event bound to a To-Do.
 * Single source of truth for the server-side guard in
 * {@see \Bitrix\Crm\Activity\Entity\BaseActivity::save()} and for timeline / editor payload builders
 * that pre-check the UX (Mantis #223889).
 */
final class CalendarEventEditPermissionChecker
{
	public function canChangeDeadline(int $userId, int $calendarEventId): bool
	{
		// Consumers from the timeline do not guarantee the calendar module is included.
		// No calendar => no gate: an unavailable calendar must not close the controls.
		if (!Loader::includeModule('calendar'))
		{
			return true;
		}

		$model = CCalendarEvent::getEventModelForPermissionCheck(
			eventId: $calendarEventId,
			userId: $userId,
		);

		// Orphaned CALENDAR_EVENT_ID: event is gone, treat as unbound and skip the gate.
		if ($model->getId() <= 0)
		{
			return true;
		}

		return (new EventAccessController($userId))->check(
			ActionDictionary::ACTION_EVENT_EDIT,
			$model,
		);
	}
}

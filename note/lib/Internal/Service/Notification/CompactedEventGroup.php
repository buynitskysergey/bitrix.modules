<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Notification;

use Bitrix\Note\Internal\Entity\History\Event;

/**
 * Every journal event of one (SCOPE, ENTITY_ID, notification kind) key that a drain pass folded
 * into a single notification, together with the one representing them.
 *
 * The whole set is carried, not just the representative: who is kept out of the recipients and
 * whose face the notification wears are answered over ALL of it (EventAuthorResolver), so a rename
 * by A and an edit by B inside one window still reach both of them.
 */
final class CompactedEventGroup
{
	/** @var Event[] */
	private array $events;

	public function __construct(
		private readonly string $kind,
		private Event $representative,
	)
	{
		$this->events = [$representative];
	}

	/**
	 * Folds one more event of the same key in. The representative is the last event of the
	 * highest-priority type: events arrive ID ASC, so ">=" keeps the newest of an equal rank while
	 * a rename never displaces the content change it arrived with.
	 */
	public function absorb(Event $event): void
	{
		$this->events[] = $event;

		if (
			NotificationShippingPolicy::priorityOf($event->getEventType())
			>= NotificationShippingPolicy::priorityOf($this->representative->getEventType())
		)
		{
			$this->representative = $event;
		}
	}

	public function getKind(): string
	{
		return $this->kind;
	}

	public function getRepresentative(): Event
	{
		return $this->representative;
	}

	/**
	 * @return Event[] in the order they were read, i.e. ID ASC
	 */
	public function getEvents(): array
	{
		return $this->events;
	}

	public function getScope(): string
	{
		return $this->representative->getScope();
	}

	public function getEntityId(): int
	{
		return $this->representative->getEntityId();
	}

	/**
	 * Smallest journal id folded in — the position a failed delivery has to be resumed from, so
	 * that a retry brings the whole group back and not only its tail.
	 */
	public function getMinEventId(): int
	{
		$minId = 0;
		foreach ($this->events as $event)
		{
			$eventId = (int)$event->getId();
			if ($eventId > 0 && ($minId === 0 || $eventId < $minId))
			{
				$minId = $eventId;
			}
		}

		return $minId;
	}
}

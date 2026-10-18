<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Notification;

use Bitrix\Note\Internal\Entity\History\Event;
use Bitrix\Note\Internal\Repository\EventAuthorRepository;

/**
 * The single answer delivery has to "who changed this article": both the face of the
 * notification (ImNotificationGateway, FROM_USER_ID) and the people kept out of its
 * recipients (NotificationRecipientService) are read from here, so the two cannot drift
 * apart into notifying an author of their own edit.
 *
 * Distinct author ids of the event, sorted ascending so the primary pick is deterministic across
 * ticks. For a compacted content change that set is the people whose patches were folded into the
 * version — NEVER the compact trigger (USER_ID), who may not even be one of them. A rename carries
 * no author set at all (EventLogService saves authors only when the caller passes them), so for it
 * the fallback to the event actor is the normal path rather than a defensive one.
 *
 * A compacted group is answered over all of its events at once (resolveGroup): the exclusion rule
 * is sole authorship of everything the group folded, not authorship of the one event that happened
 * to represent it.
 */
final class EventAuthorResolver
{
	/** @var array<int, int[]> eventId -> author ids (stable within one drainer tick) */
	private array $authorCache = [];

	public function __construct(
		private readonly EventAuthorRepository $eventAuthorRepository = new EventAuthorRepository(),
	) {}

	/**
	 * Authors of every given event in one query. Ids that have no author row are cached as an
	 * empty set as well — without that, resolve() would go back to the database for each of them,
	 * which is the whole population of a rename-only pass.
	 *
	 * @param Event[] $events
	 */
	public function preload(array $events): void
	{
		$wanted = [];
		foreach ($events as $event)
		{
			$eventId = (int)$event->getId();
			if ($eventId > 0 && !isset($this->authorCache[$eventId]))
			{
				$wanted[$eventId] = true;
			}
		}

		if ($wanted === [])
		{
			return;
		}

		$eventIds = array_keys($wanted);
		$authorIdsByEventId = $this->eventAuthorRepository->getAuthorIdsByEventIds($eventIds);
		foreach ($eventIds as $eventId)
		{
			$this->authorCache[$eventId] = self::normalize($authorIdsByEventId[$eventId] ?? []);
		}
	}

	/**
	 * @return int[] non-empty
	 */
	public function resolve(Event $event): array
	{
		$eventId = (int)$event->getId();
		$authorIds = $eventId > 0
			? ($this->authorCache[$eventId] ??= self::normalize(
				$this->eventAuthorRepository->getAuthorIdsByEventIds([$eventId])[$eventId] ?? [],
			))
			: [];

		return $authorIds !== [] ? $authorIds : [max(0, $event->getUserId())];
	}

	/**
	 * Authors of the whole compacted group: the union over its events, ascending. A recipient is
	 * kept out of a group only when this set is exactly themselves — everything folded in was
	 * their own doing — so a group carrying somebody else's event stays news for them.
	 *
	 * @return int[] non-empty
	 */
	public function resolveGroup(CompactedEventGroup $group): array
	{
		$union = [];
		foreach ($group->getEvents() as $event)
		{
			foreach ($this->resolve($event) as $authorId)
			{
				$union[$authorId] = true;
			}
		}

		$authorIds = array_keys($union);
		sort($authorIds);

		return $authorIds;
	}

	/**
	 * FROM_USER_ID for one recipient: an author of the group who is not the recipient themselves.
	 * Picked per recipient, because a group that reaches somebody at all carries an event of
	 * somebody else — showing them their own face on it would read as "you changed this article".
	 */
	public function resolveFace(CompactedEventGroup $group, int $recipientId): int
	{
		foreach ($this->resolveGroup($group) as $authorId)
		{
			if ($authorId !== $recipientId)
			{
				return $authorId;
			}
		}

		return $this->resolve($group->getRepresentative())[0];
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function normalize(array $ids): array
	{
		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
		sort($normalized);

		return $normalized;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\History;

use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Entity\History\Event;
use Bitrix\Note\Internal\Integration\Pull\HistoryPullGateway;
use Bitrix\Note\Internal\Repository\EventAuthorRepository;
use Bitrix\Note\Internal\Repository\EventRepository;

/**
 * Single write point for note's activity history (b_note_event). Fact-only records —
 * no payload/DETAILS (see EventTable). Modeled after PushNotificationService: a plain
 * Internal\Service invoked from Public\Command after a successful domain mutation.
 *
 * This service only writes: the caller (a command) owns the surrounding transaction
 * and is responsible for checking Configuration::isActivityEnabled() around the whole
 * "version + event + co-authors" write block before calling record(). After the insert
 * it also fires a best-effort live-push (HistoryPullGateway) so an open history sidebar
 * can splice the new event in without a re-fetch — see HistoryPullGateway for the
 * transaction/rollback trade-off.
 */
class EventLogService
{
	public function __construct(
		private readonly EventRepository $eventRepository = new EventRepository(),
		private readonly HistoryPullGateway $pullGateway = new HistoryPullGateway(),
		private readonly EventAuthorRepository $eventAuthorRepository = new EventAuthorRepository(),
	) {}

	/**
	 * @param int[] $authorIds co-authors for a content_changed event; persisted here (before the
	 *   push) rather than by the caller so HistoryPullGateway::enrichOne() sees the full author set
	 *   in its payload. Emitting the push before saveAuthors made the pushed tile carry only the
	 *   actor, so the activity-line chip showed a single avatar until a reload while the sidebar
	 *   feed (read later) already listed every co-author.
	 */
	public function record(string $scope, int $entityId, string $type, int $userId, ?int $versionId = null, array $authorIds = []): int
	{
		$event = Event::create($scope, $entityId, $type, $userId, $versionId);
		$eventId = $this->eventRepository->save($event);

		if ($authorIds !== [])
		{
			$this->eventAuthorRepository->saveAuthors($eventId, $authorIds);
		}

		$this->pullGateway->emit(new Event($eventId, $scope, $entityId, $type, $userId, $versionId, $event->getCreatedAt()));

		return $eventId;
	}

	/**
	 * Registers an event with an explicit historical CREATED_AT and NO live-push — used
	 * for the lazy 'created' backfill of pre-history documents. The push is deliberately
	 * skipped: this is not a "live" event, and the drainer's freshness floor would ignore
	 * it anyway (see NotificationDrainAgent).
	 */
	public function recordHistorical(string $scope, int $entityId, string $type, int $userId, DateTime $createdAt): int
	{
		return $this->eventRepository->save(Event::createAt($scope, $entityId, $type, $userId, null, $createdAt));
	}

	/**
	 * Batch counterpart of record() for bulk tree operations: writes one fact-only event per
	 * entity so history parity with the single-command paths is preserved. Deliberately fires NO
	 * live-push (HistoryPullGateway) — a large selection would otherwise storm every open sidebar
	 * with per-event pushes; the drainer/re-fetch covers eventual delivery. Best-effort as a whole
	 * (one try/catch, one flag check), mirroring the single commands' event block.
	 *
	 * @param int[] $entityIds
	 */
	public function recordMany(string $scope, array $entityIds, string $type, int $userId): void
	{
		if (!\Bitrix\Note\Internal\Configuration::isActivityEnabled())
		{
			return;
		}

		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $entityIds),
			static fn(int $id): bool => $id > 0,
		)));
		if ($normalized === [])
		{
			return;
		}

		try
		{
			foreach ($normalized as $id)
			{
				$this->eventRepository->save(Event::create($scope, $id, $type, $userId, null));
			}
		}
		catch (\Throwable)
		{
		}
	}
}

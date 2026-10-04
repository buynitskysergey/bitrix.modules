<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\Notification;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Entity\History\Event;
use Bitrix\Note\Internal\Integration\Im\ImNotificationGateway;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\EventRepository;
use Bitrix\Note\Internal\Service\Notification\NotificationRecipientService;

/**
 * [P7.T1 / ALG-03 NORMATIVE] Periodic notification drainer over b_note_event.
 * b_note_event is itself the durable queue (no separate outbox) — this agent only
 * reads it through a persistent keyset cursor (Configuration::getEventNotifyLastId())
 * and turns dedup'd/aggregated groups into im deliveries. Rows are never deleted or
 * mutated here.
 *
 * Batch atomicity is the load-bearing invariant: a batch is processed in full —
 * dedup, recipient resolution, delivery — and the cursor only moves to that
 * batch's max(ID) AFTER delivery completes, never mid-batch and never per dedup
 * group. The watchdog only gates the transition to the NEXT batch; it never
 * interrupts a batch already in flight. This is why a crash/timeout mid-run cannot
 * strand events with a smaller ID than an already-advanced cursor: the cursor
 * simply has not moved yet.
 */
final class NotificationDrainAgent
{
	private const LOCK_NAME = 'note_notify_drain';
	private const WATCHDOG_SECONDS = 10.0;
	private const RESCHEDULE_EXPRESSION = 'Bitrix\Note\Infrastructure\Agent\Notification\NotificationDrainAgent::run();';

	/**
	 * Near-immediate re-run delay used (via the legacy $pPERIOD agent hook) when a
	 * batch tail remains after the watchdog cut a run short — the leftover work is
	 * picked up on the next cron/hit tick instead of waiting out the full
	 * Configuration::getNotifyInterval() window.
	 */
	private const IMMEDIATE_RESCHEDULE_SECONDS = 1;

	/**
	 * [PRD §6.2] Closed set of nine notifiable event types — the full universe of
	 * the activity feed. Views (Block 3) and autosave never write an event, so they
	 * never reach here naturally; this list is the SQL-side filter (not a
	 * post-filter in memory) and must stay in sync with EventVisibilityPolicy's
	 * three role buckets if a new type is ever added to the feed.
	 */
	private const NOTIFIABLE_EVENT_TYPES = [
		'created',
		'content_changed',
		'title_changed',
		'moved',
		'archived',
		'archive_restored',
		'trashed',
		'trash_restored',
		'access_changed',
	];

	/**
	 * @return string Reschedule expression (agent stays registered independently
	 *                of both kill-switches below — only the tick is skipped, not
	 *                the registration; see install/index.php).
	 */
	public static function run(): string
	{
		if (!Configuration::isActivityEnabled())
		{
			return self::RESCHEDULE_EXPRESSION;
		}

		// Skipped before the cursor moves, so the flag going back on resumes where the drain stopped.
		// What accumulated meanwhile does not reach subscribers in one burst: drain()'s freshness floor
		// drops everything older than a day, and the cursor jumps past it on the first fresh event.
		if (!Configuration::isNotificationsEnabled())
		{
			return self::RESCHEDULE_EXPRESSION;
		}

		$connection = Application::getConnection();
		if (!$connection->lock(self::LOCK_NAME))
		{
			// Another tick (or a manual run) already holds the lock — do not overlap.
			return self::RESCHEDULE_EXPRESSION;
		}

		try
		{
			if (self::drain())
			{
				global $pPERIOD;
				$pPERIOD = self::IMMEDIATE_RESCHEDULE_SECONDS;
			}
		}
		finally
		{
			$connection->unlock(self::LOCK_NAME);
		}

		return self::RESCHEDULE_EXPRESSION;
	}

	/**
	 * @return bool true when the watchdog cut the run short with more work
	 *              potentially left beyond the new cursor position
	 */
	private static function drain(): bool
	{
		$eventRepository = new EventRepository();
		$documentRepository = new DocumentRepository();
		$recipientService = new NotificationRecipientService();
		$gateway = new ImNotificationGateway();

		$batchSize = Configuration::getNotifyBatchSize();
		$lastId = Configuration::getEventNotifyLastId();
		$startTime = microtime(true);
		$tailRemains = false;

		// [Block-history] Freshness floor: never notify on an event older than a day.
		// The lazy 'created' backfill inserts events with a historical CREATED_AT but a
		// fresh (large) ID; without this floor the drainer would spam subscribers with
		// "created" pings for old documents. Trade-off: the cursor still advances by the
		// batch's max(ID), so a stale row is simply skipped (never re-visited), while any
		// genuinely new row with a larger ID keeps moving the cursor past it — nothing
		// stalls. Real events are drained within minutes, well inside the window.
		$freshnessFloor = (new DateTime())->add('-1 day');

		while (true)
		{
			$events = $eventRepository->listAfterId($lastId, self::NOTIFIABLE_EVENT_TYPES, $batchSize, $freshnessFloor);
			if (empty($events))
			{
				break;
			}

			self::deliverBatch($events, $documentRepository, $recipientService, $gateway);

			// [ALG-03] Cursor = max(ID) of the whole raw batch (events are ID ASC),
			// NOT of the dedup'd groups — moved only now, after the batch was fully
			// delivered.
			$lastEvent = $events[array_key_last($events)];
			$lastId = (int)$lastEvent->getId();
			Configuration::setEventNotifyLastId($lastId);

			if ((microtime(true) - $startTime) > self::WATCHDOG_SECONDS)
			{
				$tailRemains = true;
				break;
			}
		}

		return $tailRemains;
	}

	/**
	 * @param Event[] $events
	 */
	private static function deliverBatch(
		array $events,
		DocumentRepository $documentRepository,
		NotificationRecipientService $recipientService,
		ImNotificationGateway $gateway,
	): void
	{
		// [ALG-03] Antiflood rung #1: (SCOPE, ENTITY_ID, EVENT_TYPE) -> the latest
		// event in the batch. $events is ID ASC, so a later duplicate simply
		// overwrites an earlier one for the same key — a burst inside one window
		// collapses to a single group (e.g. VERSION_ID points at the newest compact).
		$groups = [];
		foreach ($events as $event)
		{
			$key = $event->getScope() . '|' . $event->getEntityId() . '|' . $event->getEventType();
			$groups[$key] = $event;
		}

		$documentIds = [];
		foreach ($groups as $group)
		{
			if ($group->getScope() === EventTable::SCOPE_DOCUMENT)
			{
				$documentIds[$group->getEntityId()] = true;
			}
		}
		// [ALG-03] Aggregation anchor: batch documentId -> collectionId for the
		// whole dedup'd set of this batch in one query, no N+1 across the plan.
		$collectionIdMap = $documentRepository->getCollectionIds(array_keys($documentIds));

		// plan key "recipientId|eventType|collectionId" -> bucket; collectionId in
		// the key means two unrelated operations of the same type in different
		// collections never get glued together into one aggregate.
		$plan = [];
		foreach ($groups as $group)
		{
			if ($group->getScope() !== EventTable::SCOPE_DOCUMENT)
			{
				// No SCOPE=collection events exist yet (see SDD P7) — nothing to
				// anchor an aggregate to for them.
				continue;
			}

			$collectionId = $collectionIdMap[$group->getEntityId()] ?? 0;
			try
			{
				$recipients = $recipientService->resolve($group);
			}
			catch (\Throwable $e)
			{
				// [ALG-03] One group whose recipient resolution throws (data inconsistency,
				// bad ancestor row) must not abort the batch and stall the cursor — that would
				// re-fetch and re-throw the same batch forever, halting notifications for
				// everyone. Skip the offending group, log, and keep draining, matching the
				// resilience already applied at the delivery stage (deliverBucket).
				self::logError(
					'NotificationDrainAgent recipient resolution failed for event '
					. $group->getId() . ' (' . $group->getEventType() . '): ' . $e->getMessage(),
				);
				continue;
			}

			foreach ($recipients as $recipientId)
			{
				$planKey = $recipientId . '|' . $group->getEventType() . '|' . $collectionId;
				if (!isset($plan[$planKey]))
				{
					$plan[$planKey] = [
						'recipientId' => $recipientId,
						'eventType' => $group->getEventType(),
						'collectionId' => $collectionId,
						'groups' => [],
					];
				}
				$plan[$planKey]['groups'][] = $group;
			}
		}

		// [ALG-03] Whole batch, no break inside — a delivery failure for one bucket
		// must never stop the rest (see deliverBucket()).
		foreach ($plan as $bucket)
		{
			self::deliverBucket($gateway, $bucket);
		}
	}

	/**
	 * @param array{recipientId: int, eventType: string, collectionId: int, groups: Event[]} $bucket
	 */
	private static function deliverBucket(ImNotificationGateway $gateway, array $bucket): void
	{
		try
		{
			// [AC-051] A bucket spanning more than one distinct document is a mass
			// operation (e.g. archiving a whole collection) — collapse it into one
			// aggregate notification instead of N single ones.
			$distinctEntityIds = array_unique(array_map(
				static fn(Event $group): int => $group->getEntityId(),
				$bucket['groups'],
			));

			if (count($distinctEntityIds) > 1)
			{
				$gateway->notifyAggregate(
					$bucket['recipientId'],
					$bucket['eventType'],
					$bucket['collectionId'],
					$bucket['groups'],
				);
			}
			else
			{
				$gateway->notify($bucket['recipientId'], $bucket['groups'][0]);
			}
		}
		catch (\Throwable $e)
		{
			// At-least-once delivery: a repeat on the next tick is harmless (tag
			// collapsing + unread-skip in the gateway), so one bad recipient/document
			// must not stop the rest of the batch or block the cursor from advancing.
			self::logError('NotificationDrainAgent delivery failed: ' . $e->getMessage());
		}
	}

	private static function logError(string $message): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => 'NOTE_NOTIFY_DRAIN_ERROR',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => $message,
		]);
	}
}

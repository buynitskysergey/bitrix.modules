<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\Notification;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Entity\History\Event;
use Bitrix\Note\Internal\Integration\Im\ImNotificationGateway;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\EventRepository;
use Bitrix\Note\Internal\Service\Notification\CompactedEventGroup;
use Bitrix\Note\Internal\Service\Notification\EventAuthorResolver;
use Bitrix\Note\Internal\Service\Notification\NotificationRecipientService;
use Bitrix\Note\Internal\Service\Notification\NotificationShippingPolicy;

/**
 * Periodic notification drainer over b_note_event.
 * b_note_event is itself the durable queue (no separate outbox) — this agent only
 * reads it through a persistent keyset cursor and turns compacted/aggregated groups into
 * im deliveries. Rows are never deleted or mutated here.
 *
 * A tick walks the drain profiles NotificationShippingPolicy ships: each profile owns the
 * notification kinds sharing one delivery window, its own cursor option and its own
 * last-pass option, so an unwindowed profile is never held back by a windowed one and one
 * actively edited document cannot block delivery of everything else.
 *
 * The PASS is the unit of everything: reading is batched (b_note_event is read in
 * notify_batch_size pages), but compaction, the aggregate plan and delivery are built over
 * everything one pass read. A burst spanning two SQL pages is still one notification, and two
 * articles read apart are still one aggregate — the batch is a read window, never a grouping one.
 *
 * The cursor moves once, at the end of the pass, and what it moves to is decided by delivery:
 * on success the last row of the safe prefix read (see foldUntilBarrier()), on a failure the
 * position just behind the earliest event of the failing group, so the next pass sends it again.
 * That makes at-least-once delivery a fact — repeats collapse in im by NOTIFY_TAG — and a position
 * that keeps failing is stepped over after MAX_DELIVERY_ATTEMPTS passes so one poisoned row cannot
 * hold the journal for everyone.
 *
 * What counts as a failure is narrower than "something threw". Everything a pass computes before
 * it sends — recipients, the aggregate plan, the author union, the payload — holds the cursor when
 * it fails, because nothing was delivered. A throwable out of the im call itself does not: the
 * outcome is unknown there and the message is usually already stored, so the gateway logs it and
 * reports it as delivered (ImNotificationGateway::send()). Holding the cursor on that would resend
 * the whole pass on every tick until the attempt budget ran out.
 */
final class NotificationDrainAgent
{
	private const LOCK_NAME = 'note_notify_drain';
	private const WATCHDOG_SECONDS = 10.0;
	private const RESCHEDULE_EXPRESSION = 'Bitrix\Note\Infrastructure\Agent\Notification\NotificationDrainAgent::run();';

	/**
	 * Near-immediate re-run delay used (via the legacy $pPERIOD agent hook) when a
	 * tail remains after the watchdog cut a pass short — the leftover work is
	 * picked up on the next cron/hit tick instead of waiting out the full
	 * AGENT_INTERVAL window.
	 */
	private const IMMEDIATE_RESCHEDULE_SECONDS = 1;

	/**
	 * Tick interval this agent is registered with (install/migrations/agents.php spells the
	 * same 300 as a literal). Read as a constant, never from the live schedule: half of it is
	 * the tolerance of the delivery-window check. Not portal-tunable: what a recipient feels is
	 * the delivery window of NotificationShippingPolicy, and a shorter tick cannot beat it.
	 */
	private const AGENT_INTERVAL = 300;

	/**
	 * Distinct compaction groups a pass keeps before it stops reading — a memory bound on a
	 * journal that accumulated while the drainer was off, sized above the largest realistic mass
	 * operation (a whole knowledge base) so a legitimate one is never split into two aggregates.
	 * Not an option: it protects the process, not the product.
	 */
	private const MAX_GROUPS_PER_PASS = 5000;

	/**
	 * Passes one failing position is retried before it is logged and stepped over. Five passes of
	 * the shipped 10-minute window span the better part of an hour — long enough to outlast a
	 * transient im or database outage, short enough that everything behind a genuinely undeliverable
	 * row is not lost with it.
	 */
	private const MAX_DELIVERY_ATTEMPTS = 5;

	/**
	 * [PRD §6.2] Closed set of nine notifiable event types — the full universe of
	 * the activity feed. Views (Block 3) and autosave never write an event, so they
	 * never reach here naturally; this list is the SQL-side filter (not a
	 * post-filter in memory) and must stay in sync with EventVisibilityPolicy's
	 * three role buckets if a new type is ever added to the feed.
	 *
	 * What is actually delivered is narrower than this universe, and that narrowing happens in
	 * memory (see foldIntoGroups()) — the universe itself is never narrowed here.
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

	private readonly EventRepository $eventRepository;
	private readonly DocumentRepository $documentRepository;
	private readonly EventAuthorResolver $authorResolver;
	private readonly NotificationRecipientService $recipientService;
	private readonly ImNotificationGateway $gateway;

	/**
	 * @param \Closure $clock Unix time the delivery-window check and the commit-lag watermark
	 *                        compare against
	 * @param \Closure $stopwatch Monotonic seconds the watchdog measures a pass with — a separate
	 *                            seam from $clock on purpose: the window is wall-clock, the
	 *                            watchdog is elapsed real time
	 * @param NotificationRecipientService|null $recipientService Substituted only by tests: a
	 *                            recipient set is what a pass reads from storage before it sends
	 *                            anything, so a failing one is the way to exercise the pre-send
	 *                            phase (see deliverPass()).
	 */
	private function __construct(
		private readonly \Closure $clock,
		private readonly \Closure $stopwatch,
		?NotificationRecipientService $recipientService = null,
	)
	{
		$this->eventRepository = new EventRepository();
		$this->documentRepository = new DocumentRepository();
		// One author set per event for the whole tick: who is kept out of the recipients and who
		// faces the notification are the same answer, read once from the same resolver.
		$this->authorResolver = new EventAuthorResolver();
		$this->recipientService = $recipientService
			?? new NotificationRecipientService(eventAuthorResolver: $this->authorResolver);
		$this->gateway = new ImNotificationGateway(eventAuthorResolver: $this->authorResolver);
	}

	/**
	 * @param \Closure|null $clock see __construct(); injection seam for tests, CAgent evaluates
	 *                             the expression with no arguments at all.
	 * @param \Closure|null $stopwatch see __construct()
	 * @param NotificationRecipientService|null $recipientService see __construct()
	 * @return string Reschedule expression (agent stays registered independently
	 *                of the switches below — only the tick is skipped, not
	 *                the registration; see install/index.php).
	 */
	public static function run(
		?\Closure $clock = null,
		?\Closure $stopwatch = null,
		?NotificationRecipientService $recipientService = null,
	): string
	{
		if (!Configuration::isActivityEnabled())
		{
			return self::RESCHEDULE_EXPRESSION;
		}

		// Skipped before the cursor moves, so switching the option back on resumes the drain where it
		// stopped instead of replaying the gap: what accumulated meanwhile is dropped by the freshness
		// floor of drainProfile(), and the cursor jumps past it on the first fresh event.
		if (!Configuration::isNotificationsEnabled())
		{
			return self::RESCHEDULE_EXPRESSION;
		}

		// Nothing shipped, nothing to drain - neither journal read nor lock taken. Today the policy
		// always ships the `changed` profile, so this only answers a release that unships delivery
		// whole; narrowing it back is an edit of the policy, not a portal setting.
		if (NotificationShippingPolicy::shippedProfiles() === [])
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
			$drainer = new self(
				$clock ?? static fn(): int => time(),
				$stopwatch ?? static fn(): float => microtime(true),
				$recipientService,
			);

			if ($drainer->drain())
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
	 * @return bool true when a pass was cut short with more work potentially left beyond the new
	 *              cursor position
	 */
	private function drain(): bool
	{
		// One "now" for the whole tick: a pass lasts at most WATCHDOG_SECONDS, which is noise
		// against a delivery window, and stamping a pass with its start never delays the next.
		$now = ($this->clock)();
		$tailRemains = false;

		foreach (NotificationShippingPolicy::shippedProfiles() as $profile)
		{
			if (!self::isWindowElapsed($profile, $now))
			{
				continue;
			}

			if ($this->drainProfile($profile, $now))
			{
				// A tail the watchdog cut off is not a completed pass: leaving the stamp
				// untouched lets the immediate re-run read the rest a second later instead of
				// waiting out the whole window again.
				$tailRemains = true;

				continue;
			}

			Option::set('note', $profile['lastRunOption'], (string)$now);
		}

		return $tailRemains;
	}

	/**
	 * @param array $profile one entry of NotificationShippingPolicy::shippedProfiles()
	 */
	private static function isWindowElapsed(array $profile, int $now): bool
	{
		if ($profile['window'] <= 0)
		{
			return true;
		}

		$lastRun = (int)Option::get('note', $profile['lastRunOption'], 0);

		// Half a tick of tolerance: without it a tick arriving a second early because of agent
		// drift would postpone delivery by another whole interval.
		return ($now - $lastRun) >= ($profile['window'] - intdiv(self::AGENT_INTERVAL, 2));
	}

	/**
	 * @param array $profile one entry of NotificationShippingPolicy::shippedProfiles()
	 * @return bool true when reading was cut short with a tail left beyond the new cursor
	 */
	private function drainProfile(array $profile, int $now): bool
	{
		// Commit-lag watermark, computed once for the whole pass (a boundary moving between
		// batches would read an inconsistent set): an autoincrement ID is handed out at INSERT and
		// becomes visible only at COMMIT, and content_changed is written INSIDE the compaction
		// transaction (CompactDocumentCommand), so a row with a smaller ID can surface after the
		// cursor already moved past a larger one — and would then be lost for good. CREATED_AT is
		// stamped in PHP at insert time (EventTable), which makes "older than the lag" a usable
		// proxy for "committed". The guarantee is conditional: it holds as long as the lag outlasts
		// the longest writing transaction.
		//
		// What the watermark bounds is the CURSOR, not the selection — see foldUntilBarrier().
		$watermark = DateTime::createFromTimestamp($now - $profile['commitLag']);

		// Addressed by the option name the profile carries rather than by a getter per profile:
		// the cursor is runtime state, and which option holds it is part of the shipping policy.
		// Read with an empty default so that "never initialized" stays distinguishable from a
		// stored 0, which is a cursor at the head of an empty journal.
		$cursor = (string)Option::get('note', $profile['cursorOption'], '');
		if ($cursor === '')
		{
			// Baseline the cursor here and deliver nothing this pass: from zero the profile would
			// mail out every event of the last day. Bounded by the same watermark the pass reads
			// with — a baseline on a row still in flight would step over everything committing
			// behind it. On an updated portal this is the only baseline there is: the install seeds
			// the cursors (NotificationCursorInstaller), the update deliberately does not.
			Option::set('note', $profile['cursorOption'], (string)$this->eventRepository->getMaxId($watermark));

			return false;
		}

		$cursorId = (int)$cursor;
		$batchSize = Configuration::getNotifyBatchSize();
		$startedAt = ($this->stopwatch)();

		// [Block-history] Freshness floor: never notify on an event older than a day.
		// The lazy 'created' backfill inserts events with a historical CREATED_AT but a
		// fresh (large) ID; without this floor the drainer would spam subscribers with
		// "created" pings for old documents. Trade-off: the cursor still advances past a stale
		// row (it is simply never re-visited), while any genuinely new row with a larger ID keeps
		// moving the cursor past it — nothing stalls. Real events are drained within minutes,
		// well inside the window.
		$freshnessFloor = (new DateTime())->add('-1 day');

		/** @var array<string, CompactedEventGroup> $groups */
		$groups = [];
		$readId = $cursorId;
		$tailRemains = false;

		while (true)
		{
			$events = $this->eventRepository->listAfterId(
				$readId,
				self::NOTIFIABLE_EVENT_TYPES,
				$batchSize,
				$freshnessFloor,
			);
			if (empty($events))
			{
				break;
			}

			// Read position of the raw batch up to its barrier (events are ID ASC), NOT of the
			// kinds this profile delivers and not of the compacted groups.
			[$safeId, $barrier] = self::foldUntilBarrier($events, $profile['kinds'], $groups, $watermark);
			if ($safeId > 0)
			{
				$readId = $safeId;
			}

			if ($barrier !== null)
			{
				self::reportBarrierDatedAhead($barrier, $now);

				// Not a tail the immediate re-run should chase: the rows behind the barrier are not
				// late, they are uncertain until it cools, and a re-run a second later would meet
				// the same barrier. The next window reads them.
				break;
			}

			if (count($groups) >= self::MAX_GROUPS_PER_PASS)
			{
				$tailRemains = true;
				break;
			}

			if ((($this->stopwatch)() - $startedAt) > self::WATCHDOG_SECONDS)
			{
				$tailRemains = true;
				break;
			}
		}

		if ($readId === $cursorId)
		{
			// An empty selection leaves the cursor exactly where it is — it is deliberately NOT
			// moved on to the journal head. Nothing stalls in exchange: rows the freshness floor or
			// the watermark keep out of the selection are stepped over by the ID of the next row
			// that does qualify.
			return false;
		}

		$blockedFromId = $this->deliverPass($groups);
		Option::set('note', $profile['cursorOption'], (string)self::settleCursor($profile, $readId, $blockedFromId));

		// A blocked pass counts as completed: the cursor already sits behind the failure, so the
		// retry belongs to the next delivery window rather than to the immediate re-run — which
		// would otherwise burn the attempt budget in five seconds.
		return $blockedFromId === null && $tailRemains;
	}

	/**
	 * Antiflood rung #1: (SCOPE, ENTITY_ID, notification kind) -> every event sharing that key.
	 * $events is ID ASC, so a burst inside one delivery window collapses into a single group (its
	 * representative points at the newest compact, e.g. for VERSION_ID) while the group still
	 * carries the rename that arrived with it — authorship is read over all of it.
	 *
	 * The shipping filter is applied here, in memory, and never as a narrower EVENT_TYPE
	 * list in the SQL above: no index on b_note_event covers that query, so with a narrow
	 * filter LIMIT stops bounding how many rows the database has to read to fill a batch.
	 *
	 * The walk stops at the first row younger than the commit-lag watermark — the BARRIER — and
	 * everything from there on is left for a later pass. The bound has to be a barrier and not a
	 * WHERE of the query, because ID and CREATED_AT are not monotonic in b_note_event: the lazy
	 * 'created' backfill stamps the article's own creation date on a brand new ID
	 * (FeedProvider::backfillCreatedEvent()), and clocks of two web nodes drifting apart do the
	 * same on a smaller scale. Filtering the young row out instead would hand the pass the row
	 * BEHIND it, and the cursor — which only ever moves forward by ID — would step over the young
	 * one for good, silently. So the cursor may only reach the last row every row before which has
	 * cooled: a safe prefix, not a filtered set.
	 *
	 * @param Event[] $events
	 * @param string[] $profileKinds notification kinds this profile delivers
	 * @param array<string, CompactedEventGroup> $groups pass-wide map this batch is folded into
	 * @return array{0: int, 1: Event|null} last ID of this batch the cursor may be parked on (0
	 *         when the batch opens with the barrier), and the barrier row itself, null when the
	 *         whole batch was walked
	 */
	private static function foldUntilBarrier(
		array $events,
		array $profileKinds,
		array &$groups,
		DateTime $watermark,
	): array
	{
		$safeId = 0;
		foreach ($events as $event)
		{
			if ($event->getCreatedAt()->getTimestamp() > $watermark->getTimestamp())
			{
				return [$safeId, $event];
			}

			$safeId = $event->getId();

			$kind = NotificationShippingPolicy::kindOf($event->getEventType());
			if ($kind === null || !in_array($kind, $profileKinds, true))
			{
				continue;
			}

			$key = $event->getScope() . '|' . $event->getEntityId() . '|' . $kind;
			if (isset($groups[$key]))
			{
				$groups[$key]->absorb($event);

				continue;
			}

			$groups[$key] = new CompactedEventGroup($kind, $event);
		}

		return [$safeId, null];
	}

	/**
	 * A barrier dated in the future cannot cool by waiting for the watermark — a web node whose
	 * clock runs ahead stamped it — and it holds its whole tail until real time catches up. That is
	 * the safe direction to fail in (the alternative is stepping over rows for good), but it must
	 * not fail silently: one line per pass names the row and the skew.
	 */
	private static function reportBarrierDatedAhead(Event $barrier, int $now): void
	{
		$secondsAhead = $barrier->getCreatedAt()->getTimestamp() - $now;
		if ($secondsAhead <= 0)
		{
			return;
		}

		self::logError(
			'NotificationDrainAgent stopped at event ' . $barrier->getId() . ', dated '
			. $secondsAhead . ' s in the future: a web node clock runs ahead, and delivery of'
			. ' everything behind that row waits for real time to catch up',
		);
	}

	/**
	 * @param array<string, CompactedEventGroup> $groups
	 * @return int|null smallest journal ID whose delivery did not settle, null when the whole pass
	 *                  went through
	 */
	private function deliverPass(array $groups): ?int
	{
		$documentIds = [];
		$events = [];
		foreach ($groups as $group)
		{
			if ($group->getScope() === EventTable::SCOPE_DOCUMENT)
			{
				$documentIds[$group->getEntityId()] = true;
			}

			foreach ($group->getEvents() as $event)
			{
				$events[] = $event;
			}
		}

		// Authors of everything the pass folded in ONE query, before any recipient is resolved:
		// per-event reads would be one query per rename, and the group-wide author union needs
		// them all anyway.
		$this->authorResolver->preload($events);

		// Aggregation anchor: batch documentId -> collectionId for the
		// whole compacted set of this pass in one query, no N+1 across the plan.
		$collectionIdMap = $this->documentRepository->getCollectionIds(array_keys($documentIds));

		// plan key "recipientId|kind|collectionId" -> bucket; collectionId in
		// the key means two unrelated operations of the same kind in different
		// collections never get glued together into one aggregate.
		$plan = [];
		$blockedFromId = null;
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
				$recipients = $this->recipientService->resolve($group);
			}
			catch (\Throwable $e)
			{
				// One group whose recipient resolution throws (data inconsistency, bad ancestor
				// row) must not abort the pass: the rest is delivered, and the group is reported
				// as unsettled so the cursor stays behind it and the next pass tries it again.
				self::logError(
					'NotificationDrainAgent recipient resolution failed for event '
					. $group->getRepresentative()->getId()
					. ' (' . $group->getRepresentative()->getEventType() . '): ' . $e->getMessage(),
				);
				$blockedFromId = self::earlierId($blockedFromId, $group->getMinEventId());

				continue;
			}

			foreach ($recipients as $recipientId)
			{
				$planKey = $recipientId . '|' . $group->getKind() . '|' . $collectionId;
				if (!isset($plan[$planKey]))
				{
					$plan[$planKey] = [
						'recipientId' => $recipientId,
						'kind' => $group->getKind(),
						'collectionId' => $collectionId,
						'groups' => [],
					];
				}
				$plan[$planKey]['groups'][] = $group;
			}
		}

		// Whole plan, no break inside - a delivery failure for one bucket
		// must never stop the rest (see deliverBucket()).
		foreach ($plan as $bucket)
		{
			if ($this->deliverBucket($bucket))
			{
				continue;
			}

			$blockedFromId = self::earlierId($blockedFromId, self::minEventIdOf($bucket['groups']));
		}

		return $blockedFromId;
	}

	/**
	 * @param array{recipientId: int, kind: string, collectionId: int, groups: CompactedEventGroup[]} $bucket
	 * @return bool whether the bucket is settled and the cursor may pass it
	 */
	private function deliverBucket(array $bucket): bool
	{
		try
		{
			// A bucket spanning more than one distinct document is a mass operation (e.g.
			// archiving a whole collection) — collapse it into one aggregate notification
			// instead of N single ones. Counted per kind, so a document that was both edited
			// and renamed inside the window is one document here, not two.
			$distinctEntityIds = array_unique(array_map(
				static fn(CompactedEventGroup $group): int => $group->getEntityId(),
				$bucket['groups'],
			));

			if (count($distinctEntityIds) > 1)
			{
				return $this->gateway->notifyAggregate(
					$bucket['recipientId'],
					$bucket['kind'],
					$bucket['collectionId'],
					$bucket['groups'],
				);
			}

			return $this->gateway->notify($bucket['recipientId'], $bucket['groups'][0]);
		}
		catch (\Throwable $e)
		{
			// Only pre-send work reaches this: the payload's title, collection name and face are
			// read here, while the send itself never throws out of the gateway (an unknown outcome
			// is settled there, see ImNotificationGateway::send()). So nothing was delivered and
			// the group is held for the next pass.
			self::logError('NotificationDrainAgent delivery preparation failed: ' . $e->getMessage());

			return false;
		}
	}

	/**
	 * Where the cursor stands once the pass is delivered: the last row read when everything went
	 * through, and just behind the earliest unsettled event otherwise, so the next pass sends that
	 * group again (the repeat collapses in im by NOTIFY_TAG, and the settled groups with larger IDs
	 * are re-sent with it — the accepted price of at-least-once). A position that keeps failing is
	 * counted in the profile's own option and stepped over once the attempts run out.
	 */
	private static function settleCursor(array $profile, int $readId, ?int $blockedFromId): int
	{
		if ($blockedFromId === null)
		{
			self::forgetAttempts($profile);

			return $readId;
		}

		$attempts = self::countAttempts($profile, $blockedFromId) + 1;
		if ($attempts >= self::MAX_DELIVERY_ATTEMPTS)
		{
			self::logError(
				'NotificationDrainAgent gave up on event ' . $blockedFromId
				. ' after ' . $attempts . ' delivery attempts; the cursor is moved past it',
			);
			self::forgetAttempts($profile);

			return $blockedFromId;
		}

		Option::set('note', $profile['retryOption'], $blockedFromId . ':' . $attempts);

		return $blockedFromId - 1;
	}

	/**
	 * Attempts already spent on this exact position, 0 for a position the counter does not name:
	 * a failure moving to another row is a new failure and gets the full budget.
	 */
	private static function countAttempts(array $profile, int $blockedFromId): int
	{
		$stored = (string)Option::get('note', $profile['retryOption'], '');
		[$storedId, $attempts] = array_pad(explode(':', $stored), 2, '0');

		return (int)$storedId === $blockedFromId ? (int)$attempts : 0;
	}

	private static function forgetAttempts(array $profile): void
	{
		if ((string)Option::get('note', $profile['retryOption'], '') !== '')
		{
			Option::set('note', $profile['retryOption'], '');
		}
	}

	/**
	 * @param CompactedEventGroup[] $groups
	 */
	private static function minEventIdOf(array $groups): int
	{
		$minId = 0;
		foreach ($groups as $group)
		{
			$groupMinId = $group->getMinEventId();
			if ($groupMinId > 0 && ($minId === 0 || $groupMinId < $minId))
			{
				$minId = $groupMinId;
			}
		}

		return $minId;
	}

	private static function earlierId(?int $current, int $candidate): ?int
	{
		if ($candidate <= 0)
		{
			return $current;
		}

		return $current === null ? $candidate : min($current, $candidate);
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

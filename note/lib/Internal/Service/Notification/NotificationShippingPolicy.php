<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Notification;

use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Model\SubscriptionTable;

/**
 * What the product actually delivers out of the nine journal event types and the four
 * subscription modes — the single source of truth for the drainer, the im gateway, the notify
 * schema handler and the subscription transport.
 *
 * Two vocabularies meet here. An EVENT TYPE is what b_note_event stores; a KIND is what a
 * recipient sees as one notification. Several types may map onto one kind — a content change
 * and a rename of the same document read as one "article changed" — and the type priority
 * decides which of the collapsed events represents the group.
 *
 * The shipping decision is not portal-tunable at all: widening delivery is an edit of this class
 * alone — a type with its kind, the kind's delivery window and, if that window is new, a drain
 * profile of its own. There is no option in between; what a portal can still switch off is the
 * whole activity journal (Configuration::isActivityEnabled()), and nothing narrower.
 */
final class NotificationShippingPolicy
{
	private const KIND_CONTENT_CHANGED = 'content_changed';

	/**
	 * One row per shipped event type: the kind it is delivered as and its rank inside that
	 * kind. A type absent from this map keeps being written to the journal and keeps showing
	 * up in the feed and history — it is only not delivered.
	 */
	private const SHIPPED_EVENT_TYPES = [
		'content_changed' => ['kind' => self::KIND_CONTENT_CHANGED, 'priority' => 2],
		'title_changed' => ['kind' => self::KIND_CONTENT_CHANGED, 'priority' => 1],
	];

	/**
	 * Seconds a journal row is left alone before a pass may read it and move its cursor past it.
	 * Answers commit visibility, and is therefore a property of the write path rather than of the
	 * product: an ID is handed out at INSERT and becomes visible at COMMIT, so a row committed
	 * late would be stepped over by a cursor already advanced past a larger ID. Kept apart from
	 * `window`, which is antiflood and is measured in minutes — `instant` has a zero window and
	 * would otherwise be left with no guard at all.
	 */
	private const COMMIT_LAG = 60;

	/**
	 * Drain profiles — one per distinct delivery window, each with its own cursor, its own
	 * last-pass timestamp and its own retry counter, so a windowed profile never holds back an
	 * instant one. `instant` carries no shipped kind yet and is therefore not drained at all (see
	 * shippedProfiles()); it declares its option names so that the day a one-off kind becomes
	 * shipped its cursor is already in the past instead of at zero.
	 */
	private const PROFILES = [
		[
			'name' => 'changed',
			'kinds' => [self::KIND_CONTENT_CHANGED],
			// 10 min: wide enough for an editing session to collapse into a single
			// notification, narrow enough that the change still reaches a reader as news.
			'window' => 600,
			'commitLag' => self::COMMIT_LAG,
			'cursorOption' => Configuration::EVENT_NOTIFY_LAST_ID_OPTION,
			'lastRunOption' => 'event_notify_last_run',
			'retryOption' => 'event_notify_retry',
		],
		[
			'name' => 'instant',
			'kinds' => [],
			'window' => 0,
			'commitLag' => self::COMMIT_LAG,
			'cursorOption' => 'event_notify_instant_last_id',
			'lastRunOption' => 'event_notify_instant_last_run',
			'retryOption' => 'event_notify_instant_retry',
		],
	];

	/**
	 * Subscription modes the transport accepts and delivery honours, per scope. A row of any
	 * other mode stays in b_note_subscription untouched and reads as "not subscribed";
	 * unsubscribing is deliberately not restricted to this list.
	 */
	private const SHIPPED_MODES = [
		SubscriptionTable::SCOPE_DOCUMENT => [SubscriptionTable::MODE_SELF],
		SubscriptionTable::SCOPE_COLLECTION => [],
	];

	public static function isShipped(string $eventType): bool
	{
		return isset(self::SHIPPED_EVENT_TYPES[$eventType]);
	}

	/**
	 * The kind an event type is delivered as, null for a type that is not shipped. A caller
	 * that needs "the kind, or the type itself" spells that out as kindOf($type) ?? $type,
	 * which keeps the delivery code of the remaining types reachable.
	 */
	public static function kindOf(string $eventType): ?string
	{
		return self::SHIPPED_EVENT_TYPES[$eventType]['kind'] ?? null;
	}

	/**
	 * Rank of the type inside its kind: of the events collapsed into one group the highest
	 * rank represents it, so a rename never displaces the content change it arrived with.
	 */
	public static function priorityOf(string $eventType): int
	{
		return self::SHIPPED_EVENT_TYPES[$eventType]['priority'] ?? 0;
	}

	/**
	 * @return string[] every kind the product ships
	 */
	public static function shippedKinds(): array
	{
		$kinds = [];
		foreach (self::SHIPPED_EVENT_TYPES as $row)
		{
			$kinds[$row['kind']] = true;
		}

		return array_keys($kinds);
	}

	/**
	 * Seconds a kind's events are collected before they are delivered; 0 means "on the first
	 * tick that sees them". The window belongs to the kind, not to the agent's schedule.
	 */
	public static function deliveryWindowOf(string $kind): int
	{
		foreach (self::PROFILES as $profile)
		{
			if (in_array($kind, $profile['kinds'], true))
			{
				return $profile['window'];
			}
		}

		return 0;
	}

	/**
	 * Every profile carrying at least one shipped kind — what a pass walks, and the structural
	 * statement the cursor-option guards assert. A profile carrying none is skipped whole: its
	 * journal is never read and none of its options written.
	 *
	 * @return array{
	 *     name: string,
	 *     kinds: string[],
	 *     window: int,
	 *     commitLag: int,
	 *     cursorOption: string,
	 *     lastRunOption: string,
	 *     retryOption: string,
	 * }[]
	 */
	public static function shippedProfiles(): array
	{
		$shippedKinds = self::shippedKinds();

		return array_values(array_filter(
			self::PROFILES,
			static fn(array $profile): bool => array_intersect($profile['kinds'], $shippedKinds) !== [],
		));
	}

	/**
	 * @return string[] modes the transport accepts and delivery honours for the scope — empty for
	 *                  a scope that has none, never null: the transport validator and the client
	 *                  mirror read the result directly
	 */
	public static function shippedModes(string $scope): array
	{
		return self::SHIPPED_MODES[$scope] ?? [];
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal;

use Bitrix\Main\Config\Option;

final class Configuration
{
	// Feature flags: backend kill-switches. Default ON — they exist to turn a working path OFF.

	public const ACTIVITY_ENABLED_OPTION = 'activity_enabled';
	// Kept as-is despite the phase-numbered name: the key is already set on portals, and renaming it
	// would silently reset the switch back to its default.
	public const ACCESS_CASCADE_BROADCAST_OPTION = 'phase4_broadcast_enabled';

	/**
	 * Kill-switch for the document activity/history feature (events, versions, co-authors).
	 * Default is enabled. When disabled, every call site must skip the whole
	 * "version + event + co-authors" write block, not just EventLogService::record() —
	 * otherwise a version could be written without a matching event id.
	 */
	public static function isActivityEnabled(): bool
	{
		return Option::get('note', self::ACTIVITY_ENABLED_OPTION, 'Y') === 'Y';
	}

	/**
	 * Kill-switch for the ACL-change pull cascade (collection channel + personal channel of every
	 * affected recipient). Default on. Disabling it only stops live tree updates — access itself is
	 * unaffected, and a client sees the change on its next load.
	 */
	public static function isAccessCascadeBroadcastEnabled(): bool
	{
		return Option::get('note', self::ACCESS_CASCADE_BROADCAST_OPTION, 'Y') === 'Y';
	}

	// Feature flags: UI-only opt-ins. Default OFF, and they gate display only — the backend behind
	// each of them keeps working, so none of these may be called from commands, providers or agents.

	public const HISTORY_ENABLED_OPTION = 'history_enabled';
	public const HOTKEYS_ENABLED_OPTION = 'hotkeys_enabled';
	public const BACKLINKS_ENABLED_OPTION = 'backlinks_enabled';
	public const MARKDOWN_IO_ENABLED_OPTION = 'markdown_io_enabled';
	public const AI_CHAT_ENABLED_OPTION = 'ai_chat_enabled';

	/**
	 * UI-only opt-in for the history timeline surfaces (activity-line chip click + version
	 * timeline sidebar + version restore entry point). Default off. This does NOT gate the
	 * backend activity/history write path — that stays governed by isActivityEnabled(). Never
	 * call this from backend commands/providers/drainer; it is meant for the component layer only.
	 */
	public static function isHistoryUiEnabled(): bool
	{
		return Option::get('note', self::HISTORY_ENABLED_OPTION, 'N') === 'Y';
	}

	/**
	 * UI-only opt-in for the keyboard-shortcuts help surfaces (floating button, the `?`/`Cmd+/`
	 * listener and the help panel itself). Default off. The editor keymap is not gated: shortcuts
	 * keep working, only the way to look them up is hidden. Never call this from backend
	 * commands/providers/drainer.
	 */
	public static function isHotkeysUiEnabled(): bool
	{
		return Option::get('note', self::HOTKEYS_ENABLED_OPTION, 'N') === 'Y';
	}

	/**
	 * UI-only opt-in for the backlinks surfaces (the bootstrap `backlinks` slice, the widget and
	 * both controller actions behind it). Default off. It does NOT gate the link index: extraction,
	 * rebuild, repair and cleanup keep running so the index is already warm when the flag goes on.
	 * Never call this from commands or from the indexing services.
	 */
	public static function isBacklinksUiEnabled(): bool
	{
		return Option::get('note', self::BACKLINKS_ENABLED_OPTION, 'N') === 'Y';
	}

	/**
	 * UI-only opt-in for markdown import/export entry points (download .md, upload .md).
	 * Default off. Gates display only — the import/export backend keeps working regardless.
	 */
	public static function isMarkdownIoEnabled(): bool
	{
		return Option::get('note', self::MARKDOWN_IO_ENABLED_OPTION, 'N') === 'Y';
	}

	/**
	 * Opt-in for the BitrixGPT side chat in the knowledge base. Default off. Read it ONLY through
	 * AiChatAvailability — the flag is the first of several conditions (modules, service, bot), and
	 * a call site that checks the option alone would surface the panel where it cannot work.
	 */
	public static function isAiChatEnabled(): bool
	{
		return Option::get('note', self::AI_CHAT_ENABLED_OPTION, 'N') === 'Y';
	}

	// Feature flags that gate a backend path as well as the UI offering it — hence their own section:
	// switching one of these off is NOT display-only, unlike the block above.

	public const SUBTREE_INHERITANCE_OPTION = 'subtree_inheritance_enabled';
	public const NOTIFICATIONS_ENABLED_OPTION = 'notifications_enabled';

	/**
	 * Gates the CREATION of new subtree grants (a marker where the subject had none) and the UI that
	 * offers them — the scope checkbox in the permissions popup and the "Shared with me" section.
	 * Default off. Keeping, re-levelling and revoking an EXISTING marker stays always-on, and the read
	 * path is never gated: rows already materialised keep granting access, so switching the flag off
	 * is a safe rollback of the entry points, not of the data.
	 */
	public static function isSubtreeInheritanceEnabled(): bool
	{
		return Option::get('note', self::SUBTREE_INHERITANCE_OPTION, 'N') === 'Y';
	}

	/**
	 * Gates the subscription bell (document activity-line, knowledge base header, favorites block of
	 * the sidebar) together with the delivery behind it: with the flag off NotificationDrainAgent
	 * skips its tick, so a subscription made earlier stops arriving instead of becoming impossible to
	 * switch off. Subscription rows are never touched — turning the flag back on resumes delivery from
	 * the events of the last day only (the drainer's freshness floor), not from the whole backlog.
	 */
	public static function isNotificationsEnabled(): bool
	{
		return Option::get('note', self::NOTIFICATIONS_ENABLED_OPTION, 'N') === 'Y';
	}

	// Numeric settings: retention windows, agent tuning and one hard cap. Each getter clamps a
	// misconfigured value where a zero or negative one would break the path that reads it.

	public const RECYCLE_BIN_TTL_OPTION = 'recycle_bin_ttl_days';
	public const RECYCLE_BIN_TTL_DEFAULT = 30;
	public const VERSION_TTL_OPTION = 'version_ttl_days';
	public const VERSION_TTL_DEFAULT = 90;
	public const NOTIFY_INTERVAL_OPTION = 'notify_interval';
	// 5 min: KB notifications aren't urgent, and a wider drain window collapses an active
	// editing session into fewer pings before the im-side tag-collapse even kicks in.
	public const NOTIFY_INTERVAL_DEFAULT = 300;
	public const NOTIFY_BATCH_SIZE_OPTION = 'notify_batch_size';
	public const NOTIFY_BATCH_SIZE_DEFAULT = 500;
	public const BULK_SELECTION_LIMIT_OPTION = 'bulk_selection_limit';
	// [FEAT-kb2-tree-bulk-archive / Q-1] Max documents a single bulk operation may touch
	// after subtree expansion + dedup. Provisional value pending a product decision — the
	// cap is enforced once, in SelectionResolver (getSubtreeIds itself has no cap).
	public const BULK_SELECTION_LIMIT_DEFAULT = 1000;

	// [B3] Hard cap on the number of UNIQUE outgoing links indexed from one document. Content up to
	// 1 MiB can name tens of thousands of ids in a single mass paste; past this the extractor keeps
	// the first N and logs the overflow off, the way DocumentZipExportService caps attachments. Not
	// admin-tunable: it guards one bounded INSERT, not a policy.
	public const MAX_DOCUMENT_LINK_TARGETS = 1000;

	/**
	 * Days a document stays in the recycle bin before the cleanup agent hard-deletes it.
	 * -1 disables automatic cleanup (the agent self-unregisters).
	 */
	public static function getRecycleBinTtl(): int
	{
		return (int)Option::get('note', self::RECYCLE_BIN_TTL_OPTION, self::RECYCLE_BIN_TTL_DEFAULT);
	}

	/**
	 * Days a content-version snapshot (b_note_document_version) is kept before the
	 * cleanup agent deletes it. -1 disables automatic cleanup (the agent self-unregisters).
	 * Only the version row is affected — b_note_event/b_note_event_author survive TTL.
	 */
	public static function getVersionTtl(): int
	{
		return (int)Option::get('note', self::VERSION_TTL_OPTION, self::VERSION_TTL_DEFAULT);
	}

	/**
	 * [P7.T4 / ALG-03] Seconds between NotificationDrainAgent ticks — the natural
	 * agent-window rung of the antiflood ladder (dedup batch + im tag collapse are
	 * the other two).
	 */
	public static function getNotifyInterval(): int
	{
		$interval = (int)Option::get('note', self::NOTIFY_INTERVAL_OPTION, self::NOTIFY_INTERVAL_DEFAULT);

		// Clamp misconfiguration: a 0/negative interval would break the agent schedule.
		return $interval > 0 ? $interval : self::NOTIFY_INTERVAL_DEFAULT;
	}

	/**
	 * [P7.T1] Max rows fetched per NotificationDrainAgent batch iteration.
	 */
	public static function getNotifyBatchSize(): int
	{
		$batchSize = (int)Option::get('note', self::NOTIFY_BATCH_SIZE_OPTION, self::NOTIFY_BATCH_SIZE_DEFAULT);

		// Clamp misconfiguration: a 0/negative size makes listAfterId return [], so drain()
		// breaks on the first iteration and the cursor never advances — notifications stall silently.
		return $batchSize > 0 ? $batchSize : self::NOTIFY_BATCH_SIZE_DEFAULT;
	}

	/**
	 * Upper bound on the affected-document set a single bulk tree operation may resolve
	 * to. When the expanded, deduplicated selection exceeds this value the operation is
	 * aborted before any mutation (SelectionResolver reports limitExceeded).
	 */
	public static function getBulkSelectionLimit(): int
	{
		return (int)Option::get('note', self::BULK_SELECTION_LIMIT_OPTION, self::BULK_SELECTION_LIMIT_DEFAULT);
	}

	// Runtime state, not a setting: an option is merely where it is kept. Nobody configures this
	// value — the drainer writes it as it goes, which is why this is the only pair with a setter.

	public const EVENT_NOTIFY_LAST_ID_OPTION = 'event_notify_last_id';

	/**
	 * [P7.T1 / ALG-03] Persistent keyset cursor of the notification drainer — the
	 * last b_note_event.ID already fully delivered. Moved only after a whole batch
	 * is delivered, never mid-batch. Initialized to the current MAX(ID) at install/
	 * update time so enabling the drainer never floods subscribers with the
	 * already-accumulated history (see install/index.php, dev/updates/current/updater.php).
	 */
	public static function getEventNotifyLastId(): int
	{
		return (int)Option::get('note', self::EVENT_NOTIFY_LAST_ID_OPTION, 0);
	}

	public static function setEventNotifyLastId(int $id): void
	{
		Option::set('note', self::EVENT_NOTIFY_LAST_ID_OPTION, (string)$id);
	}
}

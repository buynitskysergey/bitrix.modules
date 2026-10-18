<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal;

use Bitrix\Main\Config\Option;
use Bitrix\Note\Internal\Integration\Disk\DiskGateway;

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

	// Feature flags: whole-feature opt-ins. Default OFF, and unlike the UI-only ones below they take
	// the work behind the interface with them — a surface hidden here does nothing while it is hidden.

	public const NOTIFICATIONS_ENABLED_OPTION = 'notifications_enabled';

	/**
	 * Opt-in for subscription notifications: the bell of an article's activity line, the bell and the
	 * "only notified" filter of the favorites block — and the delivery behind them, since
	 * NotificationDrainAgent skips its tick while this is off. Default off, so a portal that never
	 * set it sees no bell and mails nothing.
	 *
	 * The journal itself is not gated: events, versions and co-authors keep being written
	 * (isActivityEnabled() governs those), which is what lets the feature be switched on without a
	 * backfill. Subscription rows are never touched either — the drainer stops before it moves a
	 * cursor, and turning the flag back on resumes from the events of the last day alone (its
	 * freshness floor), not from the whole backlog.
	 */
	public static function isNotificationsEnabled(): bool
	{
		return Option::get('note', self::NOTIFICATIONS_ENABLED_OPTION, 'N') === 'Y';
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
	public const DISK_ATTACHMENTS_ENABLED_OPTION = 'disk_attachments_enabled';

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
	 * Opt-in for attaching existing Disk files to a document. Default off, and it takes BOTH this
	 * option and Disk's own universal file picker: without the picker there is no supported way to
	 * pick a file, and we are not allowed to ship a substitute for it.
	 *
	 * Gates CREATION only — the picker action refuses, and the editor offers no Disk source. Documents
	 * that already carry `[[disk ...]]` tokens keep resolving their attachments when the flag goes off,
	 * otherwise switching it would blank out saved content.
	 */
	public static function isDiskAttachmentsEnabled(): bool
	{
		if (Option::get('note', self::DISK_ATTACHMENTS_ENABLED_OPTION, 'N') !== 'Y')
		{
			return false;
		}

		return (new DiskGateway())->isUniversalFilePickerEnabled();
	}

	// Numeric settings: retention windows, agent tuning and one hard cap. Each getter clamps a
	// misconfigured value where a zero or negative one would break the path that reads it.

	public const RECYCLE_BIN_TTL_OPTION = 'recycle_bin_ttl_days';
	public const RECYCLE_BIN_TTL_DEFAULT = 30;
	public const VERSION_TTL_OPTION = 'version_ttl_days';
	public const VERSION_TTL_DEFAULT = 90;
	public const NOTIFY_BATCH_SIZE_OPTION = 'notify_batch_size';
	public const NOTIFY_BATCH_SIZE_DEFAULT = 500;
	public const BULK_SELECTION_LIMIT_OPTION = 'bulk_selection_limit';
	// Max documents a single bulk operation may touch
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
	 * Max rows fetched per NotificationDrainAgent batch iteration.
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

	/**
	 * Where the notification drainer keeps the persistent keyset cursor of its `changed` profile -
	 * the last b_note_event.ID already fully delivered. Runtime state, not a setting: nobody
	 * configures it, the drainer reads and writes the option directly (the option name belongs to
	 * the drain profile in NotificationShippingPolicy), and no getter here would have a caller.
	 * The name is a constant because NotificationShippingPolicy and the tests address the same row.
	 */
	public const EVENT_NOTIFY_LAST_ID_OPTION = 'event_notify_last_id';
}

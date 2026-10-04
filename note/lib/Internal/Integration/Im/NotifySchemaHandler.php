<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Im;

use Bitrix\Main\Localization\Loc;

/**
 * [P7.T3/T4] OnGetNotifySchema handler, registered in install/index.php via
 * EventManager::registerEventHandler('im', 'OnGetNotifySchema', ...) — same spot
 * as note's existing pull/mobile handlers. Declares note's own notification
 * group/types in the user's im settings, by the disk example (NotifySchema).
 * Without this, NOTIFY_MODULE='note' notifications fall into the generic
 * im/default group with mail/push off and no per-type toggle for the user.
 */
final class NotifySchemaHandler
{
	public static function onGetNotifySchema(): array
	{
		return [
			'note' => [
				'NOTIFY' => [
					'created' => self::schemaItem('NOTE_NOTIFY_SCHEMA_CREATED'),
					'content_changed' => self::schemaItem('NOTE_NOTIFY_SCHEMA_CONTENT_CHANGED'),
					'title_changed' => self::schemaItem('NOTE_NOTIFY_SCHEMA_TITLE_CHANGED'),
					'moved' => self::schemaItem('NOTE_NOTIFY_SCHEMA_MOVED'),
					'archived' => self::schemaItem('NOTE_NOTIFY_SCHEMA_ARCHIVED'),
					'archive_restored' => self::schemaItem('NOTE_NOTIFY_SCHEMA_ARCHIVE_RESTORED'),
					'trashed' => self::schemaItem('NOTE_NOTIFY_SCHEMA_TRASHED'),
					'trash_restored' => self::schemaItem('NOTE_NOTIFY_SCHEMA_TRASH_RESTORED'),
					'access_changed' => self::schemaItem('NOTE_NOTIFY_SCHEMA_ACCESS_CHANGED'),
				],
			],
		];
	}

	/**
	 * SITE on by default (the notify-center balloon), mail/push off by default but
	 * left togglable by the user (no DISABLED entry, unlike disk's fully-locked
	 * channels) — the whole point of registering a schema is giving note its own
	 * per-type settings row instead of falling into im/default.
	 */
	private static function schemaItem(string $nameCode): array
	{
		return [
			'NAME' => Loc::getMessage($nameCode),
			'SITE' => 'Y',
			'MAIL' => 'N',
			'PUSH' => 'N',
		];
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Im;

use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Service\Notification\NotificationShippingPolicy;

/**
 * OnGetNotifySchema handler, registered in install/migrations/events.php via
 * EventManager::registerEventHandler('im', 'OnGetNotifySchema', ...) — same spot
 * as note's existing pull/mobile handlers. Declares note's own notification
 * group/types in the user's im settings, by the disk example (NotifySchema).
 * Without this, NOTIFY_MODULE='note' notifications fall into the generic
 * im/default group with mail/push off and no per-type toggle for the user.
 */
final class NotifySchemaHandler
{
	/**
	 * Explicit kind -> lang key map for every notification note knows how to render, mirroring
	 * ImNotificationGateway's message maps. Only the kinds delivered right now reach the settings
	 * screen: a toggle for something never delivered switches nothing. The rest stay here, and in
	 * the lang files, so that shipping one more kind is an edit of NotificationShippingPolicy alone.
	 */
	private const SCHEMA_MESSAGE_KEYS = [
		'created' => 'NOTE_NOTIFY_SCHEMA_CREATED',
		'content_changed' => 'NOTE_NOTIFY_SCHEMA_CONTENT_CHANGED',
		'title_changed' => 'NOTE_NOTIFY_SCHEMA_TITLE_CHANGED',
		'moved' => 'NOTE_NOTIFY_SCHEMA_MOVED',
		'archived' => 'NOTE_NOTIFY_SCHEMA_ARCHIVED',
		'archive_restored' => 'NOTE_NOTIFY_SCHEMA_ARCHIVE_RESTORED',
		'trashed' => 'NOTE_NOTIFY_SCHEMA_TRASHED',
		'trash_restored' => 'NOTE_NOTIFY_SCHEMA_TRASH_RESTORED',
		'access_changed' => 'NOTE_NOTIFY_SCHEMA_ACCESS_CHANGED',
	];

	public static function onGetNotifySchema(): array
	{
		// Nothing is delivered while the option is off, so the settings screen is given no group to
		// hold toggles for it.
		if (!Configuration::isNotificationsEnabled())
		{
			return [];
		}

		$shippedKeys = array_intersect_key(
			self::SCHEMA_MESSAGE_KEYS,
			array_flip(NotificationShippingPolicy::shippedKinds()),
		);

		return [
			'note' => [
				'NOTIFY' => array_map(
					static fn(string $nameCode): array => self::schemaItem($nameCode),
					$shippedKeys,
				),
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

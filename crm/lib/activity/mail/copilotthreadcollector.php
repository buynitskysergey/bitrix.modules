<?php

namespace Bitrix\Crm\Activity\Mail;

use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Main\Loader;
use Bitrix\Mail\Internals\MailEntityOptionsTable;
use Bitrix\Mail\MailMessageTable;

class CopilotThreadCollector
{
	public const DEFAULT_THREAD_LIMIT = 30;

	public static function collect(int $threadId, int $ownerTypeId, int $ownerId, int $limit = self::DEFAULT_THREAD_LIMIT): string
	{
		if ($threadId <= 0 || $ownerTypeId <= 0 || $ownerId <= 0)
		{
			return '';
		}

		$activityIds = self::fetchEntityScopedIds($threadId, $ownerTypeId, $ownerId, $limit);
		if (empty($activityIds))
		{
			return '';
		}

		$activities = self::fetchActivitiesWithUf($activityIds);
		if (empty($activities))
		{
			return '';
		}

		$unsyncedMessageIds = self::fetchUnsyncedMessageIds($activities);

		$parts = [];
		foreach ($activities as $activity)
		{
			$messageId = (int)($activity['UF_MAIL_MESSAGE'] ?? 0);
			if ($messageId > 0 && isset($unsyncedMessageIds[$messageId]))
			{
				continue;
			}

			Email::uncompressActivityDescription($activity);

			$description = trim((string)($activity['DESCRIPTION'] ?? ''));
			if ($description === '')
			{
				continue;
			}

			$text = EmailQuoteStripper::strip($description);
			if ($text === '')
			{
				continue;
			}

			$parts[] = self::formatPart($activity, $text);
		}

		if (empty($parts))
		{
			return '';
		}

		return implode("\n\n", $parts);
	}

	public static function findEntityLocalRoot(int $threadId, int $ownerTypeId, int $ownerId): ?array
	{
		if ($threadId <= 0 || $ownerTypeId <= 0 || $ownerId <= 0)
		{
			return null;
		}

		$row = ActivityTable::query()
			->setSelect(['ID', 'START_TIME', 'THREAD_ID'])
			->where('THREAD_ID', $threadId)
			->where('BINDINGS.OWNER_TYPE_ID', $ownerTypeId)
			->where('BINDINGS.OWNER_ID', $ownerId)
			->where('PROVIDER_ID', Email::getId())
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		return $row ?: null;
	}

	public static function filterSyncedActivityIds(array $activities): array
	{
		$unsyncedMessageIds = self::fetchUnsyncedMessageIds($activities);

		$syncedIds = [];
		foreach ($activities as $activity)
		{
			$activityId = (int)($activity['ID'] ?? 0);
			if ($activityId <= 0)
			{
				continue;
			}

			$messageId = (int)($activity['UF_MAIL_MESSAGE'] ?? 0);
			if ($messageId > 0 && isset($unsyncedMessageIds[$messageId]))
			{
				continue;
			}

			$syncedIds[] = $activityId;
		}

		return $syncedIds;
	}

	private static function formatPart(array $activity, string $text): string
	{
		$headerParts = [];

		$directionLabel = match ((int)($activity['DIRECTION'] ?? \CCrmActivityDirection::Undefined))
		{
			\CCrmActivityDirection::Incoming => 'incoming',
			\CCrmActivityDirection::Outgoing => 'outgoing',
			default => '',
		};
		if ($directionLabel !== '')
		{
			$headerParts[] = '[' . $directionLabel . ']';
		}

		$sender = self::extractSender($activity);
		if ($sender !== '')
		{
			$headerParts[] = $sender;
		}

		$date = trim((string)($activity['START_TIME'] ?? ''));
		if ($date !== '')
		{
			$headerParts[] = '[' . $date . ']';
		}

		if (empty($headerParts))
		{
			return $text;
		}

		return implode(' ', $headerParts) . ":\n" . $text;
	}

	private static function extractSender(array $activity): string
	{
		$settings = $activity['SETTINGS'] ?? null;
		if (is_string($settings) && $settings !== '')
		{
			$settings = unserialize($settings, ['allowed_classes' => false]);
		}

		$from = is_array($settings) ? ($settings['EMAIL_META']['from'] ?? '') : '';

		return is_string($from) ? trim($from) : '';
	}

	private static function fetchEntityScopedIds(int $threadId, int $ownerTypeId, int $ownerId, int $limit): array
	{
		$rows = ActivityTable::query()
			->setSelect(['ID'])
			->where('THREAD_ID', $threadId)
			->where('BINDINGS.OWNER_TYPE_ID', $ownerTypeId)
			->where('BINDINGS.OWNER_ID', $ownerId)
			->where('PROVIDER_ID', Email::getId())
			->setOrder(['START_TIME' => 'DESC', 'ID' => 'DESC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_column($rows, 'ID');
	}

	private static function fetchActivitiesWithUf(array $activityIds): array
	{
		$result = [];

		$res = \CCrmActivity::getList(
			['START_TIME' => 'ASC', 'ID' => 'ASC'],
			['@ID' => $activityIds, 'CHECK_PERMISSIONS' => 'N'],
			false,
			false,
			[
				'ID',
				'START_TIME',
				'DESCRIPTION',
				'DESCRIPTION_TYPE',
				'DIRECTION',
				'PROVIDER_TYPE_ID',
				'SETTINGS',
				'ASSOCIATED_ENTITY_ID',
				'UF_MAIL_MESSAGE',
				'PROVIDER_ID',
			],
		);

		while ($row = $res->fetch())
		{
			$result[] = $row;
		}

		return $result;
	}

	private static function fetchUnsyncedMessageIds(array $activities): array
	{
		if (!Loader::includeModule('mail'))
		{
			return [];
		}

		$messageIds = [];
		foreach ($activities as $activity)
		{
			$messageId = (int)($activity['UF_MAIL_MESSAGE'] ?? 0);
			if ($messageId > 0)
			{
				$messageIds[] = $messageId;
			}
		}

		if (empty($messageIds))
		{
			return [];
		}

		$mailboxIds = self::fetchMailboxIds($messageIds);
		if (empty($mailboxIds))
		{
			return [];
		}

		$rows = MailEntityOptionsTable::getList([
			'select' => ['ENTITY_ID'],
			'filter' => [
				'@MAILBOX_ID' => $mailboxIds,
				'=ENTITY_TYPE' => 'MESSAGE',
				'@ENTITY_ID' => $messageIds,
				'=PROPERTY_NAME' => 'UNSYNC_BODY',
				'=VALUE' => 'Y',
			],
		])->fetchAll();

		$unsynced = [];
		foreach ($rows as $row)
		{
			$unsynced[(int)$row['ENTITY_ID']] = true;
		}

		return $unsynced;
	}

	private static function fetchMailboxIds(array $messageIds): array
	{
		$rows = MailMessageTable::getList([
			'select' => ['MAILBOX_ID'],
			'filter' => ['@ID' => $messageIds],
		])->fetchAll();

		$mailboxIds = [];
		foreach ($rows as $row)
		{
			$mailboxIds[(int)$row['MAILBOX_ID']] = true;
		}

		return array_keys($mailboxIds);
	}
}

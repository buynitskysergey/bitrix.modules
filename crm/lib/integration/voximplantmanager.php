<?php

namespace Bitrix\Crm\Integration;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use CVoxImplantHistory;

class VoxImplantManager
{
	private const ORIGIN_ID_PREFIX = 'VI_';

	private static array $callInfoCache = [];
	private static array $callDurationCache = [];

	public static function getCallInfo(?string $callId): ?array
	{
		if (!Loader::includeModule('voximplant'))
		{
			return null;
		}

		if (empty($callId))
		{
			return null;
		}

		if (!isset(self::$callInfoCache[$callId]))
		{
			$info = CVoxImplantHistory::getBriefDetails($callId);
			if (is_array($info))
			{
				self::$callInfoCache[$callId] = $info;
			}
		}

		return self::$callInfoCache[$callId] ?? null;
	}

	public static function getCallDuration(string $callId): ?int
	{
		if (array_key_exists($callId, self::$callDurationCache))
		{
			return self::$callDurationCache[$callId];
		}

		$info = self::getCallInfo($callId) ?? [];

		return isset($info['DURATION']) ? (int)$info['DURATION'] : null;
	}

	/**
	 * Warms the durations of many calls with a single statistic query so that the following
	 * getCallDuration() calls hit the warm cache instead of one getBriefDetails() query per call.
	 * Calls without a statistic row are cached as null so a later lookup still hits the cache.
	 *
	 * @param string[] $callIds
	 */
	public static function warmCallDurations(array $callIds): void
	{
		$missing = [];
		foreach ($callIds as $callId)
		{
			if (is_string($callId) && $callId !== '' && !array_key_exists($callId, self::$callDurationCache))
			{
				$missing[$callId] = true;
			}
		}

		if (empty($missing))
		{
			return;
		}

		if (!Loader::includeModule('voximplant'))
		{
			return;
		}

		$rows = \Bitrix\Voximplant\StatisticTable::getList([
			'select' => ['CALL_ID', 'CALL_DURATION'],
			'filter' => ['@CALL_ID' => array_keys($missing)],
		]);
		foreach ($rows as $row)
		{
			$callId = (string)$row['CALL_ID'];
			self::$callDurationCache[$callId] = (int)$row['CALL_DURATION'];
			unset($missing[$callId]);
		}

		// cache the remaining misses as null to avoid re-querying them
		foreach (array_keys($missing) as $callId)
		{
			self::$callDurationCache[$callId] = null;
		}
	}

	public static function saveComment(string $callId, $comment): void
	{
		if (!Loader::includeModule('voximplant'))
		{
			return;
		}

		$comment = is_string($comment) ? $comment : '';

		CVoxImplantHistory::saveComment($callId, $comment);

		if (isset(self::$callInfoCache[$callId]))
		{
			unset(self::$callInfoCache[$callId]);
		}
	}

	final public static function isActivityBelongsToVoximplant(array $activityFields): bool
	{
		return (
			isset($activityFields['PROVIDER_ID'])
			&& $activityFields['PROVIDER_ID'] === Call::ACTIVITY_PROVIDER_ID
			&& isset($activityFields['ORIGIN_ID'])
			&& is_string($activityFields['ORIGIN_ID'])
			&& self::isVoxImplantOriginId($activityFields['ORIGIN_ID'])
		);
	}

	final public static function isVoxImplantOriginId(string $originId): bool
	{
		return str_starts_with($originId, self::ORIGIN_ID_PREFIX);
	}

	final public static function extractCallIdFromOriginId(string $originId): string
	{
		if (!self::isVoxImplantOriginId($originId))
		{
			throw new ArgumentException('originId should belong to voximplant');
		}

		return str_replace(self::ORIGIN_ID_PREFIX, '', $originId);
	}

	final public static function insertPrefix(string $callId): string
	{
		if (self::isVoxImplantOriginId($callId))
		{
			return $callId;
		}

		return self::ORIGIN_ID_PREFIX . $callId;
	}
}

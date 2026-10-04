<?php
namespace Bitrix\Timeman\Helper;

use Bitrix\Main\Config;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type;


class TimeHelper
{
	protected static $instance;
	private $dateFormat = false;
	private $timezoneOffsets = [];
	/** @var array */
	private $formattedOffsets = [];
	/** @var array */
	private $usersUtcOffsets = [];
	/**
	 * Request-scoped cache of the stable effective IANA time zone id per user.
	 * The IANA id (unlike a date-specific offset) is stable, so it is safe to keep for the request.
	 * Date-specific offsets are never cached: they are derived on demand via getOffsetAt().
	 * @var array<int, string>
	 */
	private $effectiveTimeZoneIds = [];

	/**
	 * @return static
	 */
	public static function getInstance()
	{
		if (!static::$instance)
		{
			static::$instance = new static();
		}
		return static::$instance;
	}

	public function getServerUtcOffset()
	{
		return date('Z');
	}

	/**
	 * Server-zone absolute UTC offset (seconds) at the given instant, date-aware (honours DST), replacing
	 * the fixed-"now" date('Z') / date('Z') as a server anchor.
	 *
	 * Unlike getServerUtcOffset() this method accepts an explicit timestamp so callers that already know
	 * the absolute event instant can derive the server-side offset for that exact moment without depending
	 * on the current wall-clock. Extracted from the duplicates in timeman_user.php, timeman_report_full.php
	 * and timeman.php so all three share a single implementation.
	 *
	 * @param int $timestamp Unix seconds (UTC)
	 * @return int seconds, signed
	 */
	public function getServerOffsetAt(int $timestamp): int
	{
		$serverZone = new \DateTimeZone($this->getDefaultServerTimezoneName());

		return (new \DateTime('@' . $timestamp))->setTimezone($serverZone)->getOffset();
	}

	/**
	 * Resolves the effective IANA time zone id for the user (ALG-01).
	 *
	 * Source of truth is the IANA TIME_ZONE, never the deprecated TIME_ZONE_OFFSET:
	 *  - if the time zone feature is disabled (OptionEnabled()/Enabled()) -> server default zone;
	 *  - for the current user -> runtime TIME_ZONE, then browser-auto cookie;
	 *  - for any other user -> ONLY persisted b_user.TIME_ZONE (no CTimeZone::GetOffset(other),
	 *    whose deprecated branch falls back to TIME_ZONE_OFFSET);
	 *  - fallback: portal main.default_time_zone, then server default.
	 *
	 * The resolved IANA id is stable, so it is cached per request. Date-specific offsets are not.
	 *
	 * @param int $userId
	 * @return string
	 */
	public function resolveEffectiveTimeZoneId(int $userId): string
	{
		if (isset($this->effectiveTimeZoneIds[$userId]))
		{
			return $this->effectiveTimeZoneIds[$userId];
		}

		$timeZoneId = $this->calculateEffectiveTimeZoneId($userId);
		$this->effectiveTimeZoneIds[$userId] = $timeZoneId;

		return $timeZoneId;
	}

	/**
	 * Same effective-IANA resolution as resolveEffectiveTimeZoneId() but for a persisted TIME_ZONE that
	 * the caller already has in hand (e.g. loaded once via a grid query), so it performs NO per-user
	 * b_user read. This avoids the getPersistedTimeZoneId() -> CUser::GetList() fan-out (N+1, Q-1) when a
	 * collection of "other" users must be resolved. The result is seeded into the same request-scoped
	 * cache, so subsequent resolveEffectiveTimeZoneId($userId) calls for those users hit the cache and
	 * issue no extra queries.
	 *
	 * The fallback chain is identical to resolveEffectiveTimeZoneId(): feature-enabled -> the supplied
	 * persisted zone -> portal default -> server default, with the same isValidIanaTimeZoneId() gate, so
	 * for the same inputs both methods return the same zone. For the current user the runtime resolution
	 * still takes precedence (the caller's persisted value may be stale vs the live runtime/cookie zone).
	 *
	 * @param int $userId
	 * @param string $persistedTimeZoneId persisted b_user.TIME_ZONE already known to the caller
	 * @return string
	 */
	public function resolveEffectiveTimeZoneIdFromPersisted(int $userId, string $persistedTimeZoneId): string
	{
		// Current-user check MUST precede the cache read: the grid or another caller may have seeded the
		// cache with the persisted zone for a user who is also the current user. If we served that cached
		// persisted value, the runtime/cookie priority documented in the docblock would be silently ignored.
		// resolveEffectiveTimeZoneId() re-derives from the runtime source and re-seeds the cache correctly.
		if ($userId === $this->getCurrentUserId())
		{
			return $this->resolveEffectiveTimeZoneId($userId);
		}

		if (isset($this->effectiveTimeZoneIds[$userId]))
		{
			return $this->effectiveTimeZoneIds[$userId];
		}

		$timeZoneId = $this->isTimeZoneFeatureEnabled()
			? $this->resolvePersistedZoneWithFallback($persistedTimeZoneId)
			: $this->getDefaultServerTimezoneName();
		$this->effectiveTimeZoneIds[$userId] = $timeZoneId;

		return $timeZoneId;
	}

	private function calculateEffectiveTimeZoneId(int $userId): string
	{
		if (!$this->isTimeZoneFeatureEnabled())
		{
			return $this->getDefaultServerTimezoneName();
		}

		if ($userId === $this->getCurrentUserId())
		{
			$timeZoneId = $this->getCurrentUserRuntimeTimeZoneId();
		}
		else
		{
			$timeZoneId = $this->getPersistedTimeZoneId($userId);
		}

		return $this->resolvePersistedZoneWithFallback($timeZoneId);
	}

	/**
	 * Applies the shared portal-default -> server-default fallback (with the IANA validity gate) to an
	 * already-resolved candidate zone id. Extracted so resolveEffectiveTimeZoneId() and
	 * resolveEffectiveTimeZoneIdFromPersisted() stay byte-for-byte identical in their fallback behavior.
	 */
	private function resolvePersistedZoneWithFallback(string $timeZoneId): string
	{
		if ($timeZoneId === '' || !$this->isValidIanaTimeZoneId($timeZoneId))
		{
			$timeZoneId = $this->getPortalDefaultTimeZoneId();
		}
		if ($timeZoneId === '' || !$this->isValidIanaTimeZoneId($timeZoneId))
		{
			$timeZoneId = $this->getDefaultServerTimezoneName();
		}

		return $timeZoneId;
	}

	protected function isTimeZoneFeatureEnabled(): bool
	{
		return \CTimeZone::OptionEnabled() && \CTimeZone::Enabled();
	}

	protected function getPortalDefaultTimeZoneId(): string
	{
		return (string)Config\Option::get('main', 'default_time_zone', '');
	}

	/**
	 * Real DateTimeZone built from the effective IANA id (NOT a synthetic '+HH:MM' zone).
	 * This zone carries DST rules, so derived offsets are date-aware.
	 *
	 * @param int $userId
	 * @return \DateTimeZone
	 */
	public function getUserDateTimeZone(int $userId): \DateTimeZone
	{
		return new \DateTimeZone($this->resolveEffectiveTimeZoneId($userId));
	}

	/**
	 * Date-aware ABSOLUTE UTC offset of the user's zone at the given instant (ALG-02).
	 *
	 * @param int $userId
	 * @param int $timestamp ABSOLUTE point in time = UTC Unix-seconds (e.g. RECORDED_*_TIMESTAMP),
	 *                        NOT wall-time and NOT seconds-of-day. Callers holding a DateTime pass $dt->getTimestamp().
	 * @return int absolute UTC offset in seconds (the same semantics as START_OFFSET/STOP_OFFSET)
	 */
	public function getOffsetAt(int $userId, int $timestamp): int
	{
		$dateTime = new \DateTime('@' . $timestamp);
		$dateTime->setTimezone($this->getUserDateTimeZone($userId));

		return $dateTime->getOffset();
	}

	/**
	 * Inverse direction of getOffsetAt (ALG-02): builds an absolute UTC timestamp from the user's
	 * wall-time on the event date, expressed in the user's real IANA zone.
	 *
	 * DST policy (resolved by the DateTime constructor in the real zone):
	 *  - gap (spring-forward, the local time does not exist): forward-shift as-is;
	 *  - fold (fall-back, the local time is ambiguous): deterministic PHP DateTime default
	 *    (zone-dependent, NOT "always first occurrence"); pinned by a regression test.
	 *
	 * @param int $userId
	 * @param string $date date of the event in 'Y-m-d' format
	 * @param int $seconds wall-seconds from midnight of the event date
	 * @return int absolute UTC timestamp
	 */
	public function buildTimestampFromWallTime(int $userId, string $date, int $seconds): int
	{
		$time = str_pad((string)$this->getHours($seconds), 2, '0', STR_PAD_LEFT)
			. ':' . str_pad((string)$this->getMinutes($seconds), 2, '0', STR_PAD_LEFT)
			. ':' . str_pad((string)$this->getSeconds($seconds), 2, '0', STR_PAD_LEFT);

		$dateTime = new \DateTime($date . ' ' . $time, $this->getUserDateTimeZone($userId));

		return $dateTime->getTimestamp();
	}

	/**
	 * Reconstructs a historical DateTime "as it was recorded" from the absolute timestamp and the
	 * stored snapshot offset. This is the only NEW legitimate caller of createTimezoneByOffset()
	 * (some retained legacy methods still call it until they are migrated in P5): the snapshot offset
	 * is a frozen historical fact, so a synthetic fixed-offset zone is correct here.
	 *
	 * @param int $timestamp absolute UTC Unix-seconds of the recorded instant
	 * @param int $snapshotOffset absolute UTC offset stored at recording time (START_OFFSET/STOP_OFFSET)
	 * @return \DateTime
	 */
	public function reconstructHistoricalDateTime(int $timestamp, int $snapshotOffset): \DateTime
	{
		$dateTime = new \DateTime('@' . $timestamp);
		$dateTime->setTimezone($this->createTimezoneByOffset($snapshotOffset));

		return $dateTime;
	}

	protected function getCurrentUserId(): int
	{
		global $USER;

		return is_object($USER) ? (int)$USER->GetID() : 0;
	}

	protected function getCurrentUserRuntimeTimeZoneId(): string
	{
		global $USER;

		if (!is_object($USER))
		{
			return '';
		}

		$timeZoneId = (string)$USER->GetParam('TIME_ZONE');
		if ($timeZoneId === '' && \CTimeZone::IsAutoTimeZone($USER->GetParam('AUTO_TIME_ZONE')))
		{
			$cookie = \CTimeZone::getTzCookie();
			if ($cookie !== null)
			{
				$timeZoneId = $cookie;
			}
		}

		return $timeZoneId;
	}

	/**
	 * Reads the persisted IANA TIME_ZONE of another user from b_user.
	 * Deliberately does NOT call CTimeZone::GetOffset($otherUser): its deprecated branch falls back
	 * to TIME_ZONE_OFFSET, which this contract must never return.
	 */
	protected function getPersistedTimeZoneId(int $userId): string
	{
		$user = \CUser::GetList(
			'id',
			'asc',
			['ID_EQUAL_EXACT' => $userId],
			['FIELDS' => ['TIME_ZONE']]
		)->Fetch();

		return is_array($user) ? (string)$user['TIME_ZONE'] : '';
	}

	/**
	 * Accepts ONLY real IANA timezone identifiers (live timezone-aware resolution, ALG-01).
	 *
	 * `new \DateTimeZone($id)` is intentionally NOT used as the validator: PHP also accepts
	 * fixed-offset strings ('+03:00', '-05:00', '+0300'), 'GMT+3' and legacy abbreviations
	 * ('CEST', 'MSK'), none of which carry DST rules. Letting such a value through would break the
	 * P1 invariant (live calculations must run through a real DST-aware zone, never a synthetic
	 * '+HH:MM'). We therefore require an exact, case-sensitive match against the canonical IANA
	 * list, ALL_WITH_BC so backward-compatible aliases (e.g. 'Europe/Kiev') are preserved.
	 *
	 * Synthetic fixed-offset zones remain legitimate ONLY for historical reconstruction
	 * (see reconstructHistoricalDateTime()/createTimezoneByOffset()), never here.
	 */
	private function isValidIanaTimeZoneId(string $timeZoneId): bool
	{
		if ($timeZoneId === '')
		{
			return false;
		}

		static $ianaIds = null;
		if ($ianaIds === null)
		{
			$ianaIds = array_flip(timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC));
		}

		return isset($ianaIds[$timeZoneId]);
	}

	public function getTimeRegExp($ignoreAmPmMode = false)
	{
		$exp = '#^([0-9]|0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]';
		if (!$ignoreAmPmMode && $this->isAmPmMode())
		{
			$exp .= '[ apm]{0,3}';
		}
		return $exp . '$#';
	}

	public function convertSecondsToHoursMinutes($seconds, $leadingHourZero = true)
	{
		if ($seconds === null)
		{
			return null;
		}
		return ($leadingHourZero ? str_pad($this->getHours($seconds), 2, 0, STR_PAD_LEFT) : $this->getHours($seconds))
			   . ':' . str_pad($this->getMinutes($seconds), 2, 0, STR_PAD_LEFT);
	}

	/**
	 * Single source of truth for a signed "+/-HH:MM" UTC-offset label.
	 *
	 * The numeric part is built from the ABSOLUTE offset and the sign is prepended exactly once (zero
	 * carries no sign), so callers never end up with a double "--HH:MM" by combining a separate sign
	 * helper with convertSecondsToHoursMinutes() - the latter already emits its own leading "-" for a
	 * negative argument. Both the record report hint and RecordFormHelper's "(UTC ...)" label reuse this.
	 *
	 * @param int $offsetSeconds signed absolute UTC offset in seconds
	 * @return string e.g. "+04:00", "-03:30", "00:00" for zero
	 */
	public function formatSignedOffset(int $offsetSeconds): string
	{
		$sign = $offsetSeconds === 0 ? '' : ($offsetSeconds > 0 ? '+' : '-');

		return $sign . $this->convertSecondsToHoursMinutes(abs($offsetSeconds));
	}

	public function convertSecondsToHoursMinutesAmPm($seconds)
	{
		$ts = $this->buildTimestampByFormattedDateForServer(convertTimeStamp()) + $seconds % 86400;
		return formatDate($this->isAmPmMode() ? 'h:i a' : 'H:i', $ts);
	}

	public function convertHoursMinutesToSeconds($value)
	{
		if (!is_string($value))
		{
			return 0;
		}
		if ($value <> '')
		{
			list($hour, $min) = explode(':', $value, 2);

			if ($this->isAmPmMode() && preg_match('/(am|pm)/i', $min, $match))
			{
				$ampm = mb_strtolower($match[0]);
				if ($ampm == 'pm' && $hour < 12)
				{
					$hour += 12;
				}
				elseif ($ampm == 'am' && $hour == 12)
				{
					$hour = 0;
				}
			}

			$value = abs(((int) $hour) * 3600 + ((int) $min) * 60);
			if ($value >= 86400)
			{
				return 86399;
			}
		}
		else
		{
			return 0;
		}
		return $value;
	}

	public function getUtcNowTimestamp()
	{
		return (int)gmdate('U');
	}

	public function getUtcTimestampForUserTime($userId, $daySeconds, $date = null)
	{
		$timeZone = $this->getUserTimezone($userId);
		if (!($timeZone instanceof \DateTimeZone))
		{
			return null;
		}
		if ($date === null)
		{
			$date = $this->getUserDateTimeNow($userId);
		}
		$dateFormatted = $date->format('Y-m-d');

		$seconds = str_pad($this->getSeconds($daySeconds), 2, '0', STR_PAD_LEFT);
		$dateTime = \DateTime::createFromFormat(
			'Y-m-d H:i:s',
			$dateFormatted . ' ' . $this->convertSecondsToHoursMinutes($daySeconds) . ':' . $seconds,
			$timeZone
		);
		if (!$dateTime)
		{
			return null;
		}
		return $dateTime->getTimestamp();
	}

	public function convertSecondsToHoursMinutesLocal($seconds, $keepZeroHours = true)
	{
		$sign = $seconds < 0 ? '-' : '';
		$seconds = abs($seconds);
		$hours = $this->getHours($seconds);
		$result = '';
		if ($keepZeroHours || $hours != 0)
		{
			$result = $sign . $hours . Loc::getMessage('JS_CORE_H') . ' ';
		};
		return $result . $this->getMinutes($seconds) . Loc::getMessage('JS_CORE_M');
	}

	public function getMinutes($secs)
	{
		return intval(($secs % TimeDictionary::SECONDS_PER_HOUR) / TimeDictionary::SECONDS_PER_MINUTE);
	}

	public function getSeconds($secs)
	{
		return intval(($secs % TimeDictionary::SECONDS_PER_HOUR % TimeDictionary::MINUTES_PER_HOUR));
	}

	public function getHours($secs)
	{
		return intval($secs / TimeDictionary::SECONDS_PER_HOUR);
	}

	public function convertUtcTimestampToDaySeconds($timestamp, $offset = 0)
	{
		return $this->getSecondsFromDateTime(
			$this->createDateTimeFromTimestamp($timestamp, $offset)
		);
	}

	private function createDateTimeFromTimestamp($timestamp, $offset = 0)
	{
		if ($offset instanceof \DateTimeZone)
		{
			$tz = $offset;
		}
		else
		{
			$tz = $this->createTimezoneByOffset($offset);
		}
		return $this->buildDateTimeFromFormat('U', $timestamp, $tz);
	}

	public function convertUtcTimestampToHoursMinutesAmPm($timestamp, $offset = 0)
	{
		return $this->convertSecondsToHoursMinutesAmPm(
			$this->convertUtcTimestampToDaySeconds($timestamp, $offset)
		);
	}

	public function convertUtcTimestampToHoursMinutes($timestamp, $offset = 0)
	{
		return $this->convertSecondsToHoursMinutes(
			$this->convertUtcTimestampToDaySeconds($timestamp, $offset)
		);
	}

	/**
	 * @param \DateTime|Type\DateTime $dateTime
	 * @return int
	 */
	public function getSecondsFromDateTime($dateTime)
	{
		$parts = explode(':', $dateTime->format('G:i:s'));
		return (int)$parts[0] * TimeDictionary::SECONDS_PER_HOUR
			   + (int)$parts[1] * TimeDictionary::SECONDS_PER_MINUTE
			   + (int)$parts[2];
	}

	public function getFormattedOffset($offsetSeconds, $leadingHourZero = true)
	{
		if (!isset($this->formattedOffsets[$offsetSeconds]))
		{
			$gmtOffset = $offsetSeconds > 0 ? '+' : '-';
			$res = $gmtOffset . $this->convertSecondsToHoursMinutes(abs($offsetSeconds), $leadingHourZero);
			$this->formattedOffsets[$offsetSeconds] = $res;
		}
		return $this->formattedOffsets[$offsetSeconds];
	}

	/**
	 * Legacy absolute UTC offset "as of now".
	 * @deprecated Use the date-aware getOffsetAt($userId, $timestamp) instead. Kept for historical
	 *             compatibility with consumers not yet migrated (see P5).
	 */
	public function getUserUtcOffset($userId)
	{
		$userId = (int)$userId;
		if (!isset($this->usersUtcOffsets[$userId]))
		{
			$dateTimeServer = new \DateTime('now', $this->createTimezoneByOffset($this->getServerUtcOffset()));
			$this->usersUtcOffsets[$userId] = $dateTimeServer->getOffset() + $this->getUserToServerOffset($userId);
		}
		return $this->usersUtcOffsets[$userId];
	}

	/**
	 * Legacy delta user-server offset "as of now".
	 *
	 * Kept for historical compatibility only; new calculations must use the date-aware
	 * getOffsetAt()/getUserDateTimeZone() contract instead. The long-lived persistent CPHPCache
	 * (~365 days) that made this value DST-incompatible has been removed: the value is now only
	 * request-scoped (and can still be bulk-preloaded via setTimezoneOffsets()).
	 *
	 * @param int|null $userId
	 * @return int
	 */
	public function getUserToServerOffset($userId = null)
	{
		$userId = ($userId === null ? -1 : (int) $userId);

		if (!isset($this->timezoneOffsets[$userId]))
		{
			$this->timezoneOffsets[$userId] = (int) \CTimeZone::getOffset($userId, true);
		}

		return $this->timezoneOffsets[$userId];
	}

	public function setTimezoneOffsets($offsetsByUserId)
	{
		$this->timezoneOffsets = $offsetsByUserId;
	}

	public function getUserDateTimeNow($userId)
	{
		$dateTime = $this->createDateTimeFromTimestamp($this->getUtcNowTimestamp());
		$dateTime->setTimezone($this->getUserTimezone($userId));
		return $dateTime;
	}

	/**
	 * Legacy synthetic '+HH:MM' zone built from the "as of now" offset (no DST rules).
	 * @deprecated Use getUserDateTimeZone($userId) for a real DST-aware DateTimeZone(IANA).
	 *             Synthetic zones are legitimate only for historical reconstruction
	 *             (see reconstructHistoricalDateTime()).
	 */
	public function getUserTimezone($userId)
	{
		$userOffset = $this->getUserUtcOffset($userId);
		return $this->createTimezoneByOffset($userOffset);
	}

	/**
	 * @param string $format
	 * @param string $dateString
	 * @param int $userId
	 * @return null|\DateTime
	 */
	public function createUserDateTimeFromFormat($format, $dateString, $userId)
	{
		return $this->buildDateTimeFromFormat($format, $dateString, $this->getUserTimezone($userId));
	}

	public function createDateTimeFromFormat($format, $dateString, $offset = 0)
	{
		return $this->buildDateTimeFromFormat($format, $dateString, $this->createTimezoneByOffset($offset));
	}

	private function buildDateTimeFromFormat($format, $formattedDate, $timezone)
	{
		$dateTime = false;
		if ($format === 'U')
		{
			if ((int)$formattedDate > 0)
			{
				$dateTime = \DateTime::createFromFormat(
					$format,
					$formattedDate
				);
				$dateTime->setTimezone($timezone);
			}
		}
		else
		{
			$dateTime = \DateTime::createFromFormat(
				$format,
				$formattedDate,
				$timezone
			);
		}
		return $dateTime === false ? null : $dateTime;
	}

	public function getCurrentServerDateFormatted()
	{
		$date = $this->buildDateTimeFromFormat(
			'U',
			$this->getUtcNowTimestamp(),
			new \DateTimeZone($this->getDefaultServerTimezoneName())
		);
		return $date->format('Y-m-d');
	}

	public function getTimestampByUserSecondsFromTimestamp($seconds, $initialTimestamp = null, $initialOffset = null)
	{
		if (is_null($seconds))
		{
			return null;
		}
		$userDateTime = $this->createDateTimeFromFormat('U', $initialTimestamp, $initialOffset);
		return $this->getTimestampOfTime($userDateTime, $seconds);
	}

	public function getTimestampByUserDate($formattedDate, $userId, $format = null)
	{
		$dateFormat = $this->getDateFormat();
		if ($format !== null)
		{
			$dateFormat = $format;
		}

		// Parse only the calendar date (time 00:00 is implied) and rebuild midnight of that
		// date directly in the user's real IANA zone, so the offset is date-aware (DST-correct).
		$ts = $this->buildTimestampByFormattedDateForServer($formattedDate, $dateFormat);
		if ($ts <= 0)
		{
			return null;
		}
		// $ts is server-midnight produced by MakeTimeStamp()/mktime() in the SERVER zone, so the
		// calendar date must be read back in that same zone (date()), NOT in UTC (gmdate()):
		// on a server with a positive UTC offset gmdate() would roll the date back one day.
		$date = date('Y-m-d', $ts);

		return $this->buildTimestampFromWallTime((int)$userId, $date, 0);
	}

	public function buildTimestampByFormattedDateForServer($formattedDate, $dateFormat = false)
	{
		// utc timestamp, at the given date (and time 00:00) for the server
		return MakeTimeStamp($formattedDate, $dateFormat);
	}

	public function getTimestampByUserSeconds($userId, $seconds)
	{
		if (is_null($seconds))
		{
			return null;
		}
		$userDateTime = $this->getUserDateTimeNow($userId);
		return $this->getTimestampOfTime($userDateTime, $seconds);
	}

	/**
	 * @param \DateTime $dateTime
	 * @param $seconds
	 * @return mixed
	 */
	private function getTimestampOfTime($dateTime, $seconds)
	{
		$this->setTimeFromSeconds($dateTime, $seconds);
		return $dateTime->getTimestamp();
	}

	/**
	 * @param \DateTime $dateTime
	 * @param $seconds
	 */
	public function setTimeFromSeconds($dateTime, $seconds)
	{
		$dateTime->setTime($this->getHours($seconds), $this->getMinutes($seconds), $this->getSeconds($seconds));
	}

	/**
	 * Builds a synthetic fixed-offset DateTimeZone ('+HH:MM'), which carries NO DST rules.
	 * @internal The only NEW legitimate caller is historical reconstruction from a frozen snapshot
	 *           offset (reconstructHistoricalDateTime()); some retained legacy methods still call it
	 *           until they are migrated in P5. Do not use for live timezone-aware calculations in new
	 *           code; use getUserDateTimeZone()/getOffsetAt() instead.
	 */
	public function createTimezoneByOffset($offsetSeconds)
	{
		$offsetSeconds = (int)$offsetSeconds;
		static $timezonesByOffset = [];
		if (!isset($timezonesByOffset[$offsetSeconds]))
		{
			$timezonesByOffset[$offsetSeconds] = new \DateTimeZone($this->getFormattedOffset($offsetSeconds));
		}
		return $timezonesByOffset[$offsetSeconds];
	}

	public function getDayOfWeek(\DateTime $dateTime)
	{
		return (int)$dateTime->format('N');
	}

	public function getDateFormat()
	{
		if ($this->dateFormat)
		{
			return $this->dateFormat;
		}
		return defined('FORMAT_DATE') ? FORMAT_DATE : false;
	}

	protected function isAmPmMode()
	{
		return isAmPmMode();
	}

	public function normalizeSeconds($seconds)
	{
		$m = TimeDictionary::SECONDS_PER_DAY;
		return ($seconds % $m + $m) % $m;
	}

	public function getPreviousDayOfWeek(\DateTime $userDateTime)
	{
		$today = $this->getDayOfWeek($userDateTime);
		$today = $today - 1;
		if ($today < 1)
		{
			$today = 7;
		}
		return $today;
	}

	public function getNextDayOfWeek(\DateTime $userDateTime)
	{
		$today = $this->getDayOfWeek($userDateTime);
		$today = $today + 1;
		if ($today > 7)
		{
			$today = 1;
		}
		return $today;
	}

	public function getServerIsoDate()
	{
		return date('c');
	}

	public function getDefaultServerTimezoneName()
	{
		return date_default_timezone_get();
	}

	/**
	 * @param \DateTime|int $dateTime
	 * @param $format
	 * @param string|null $languageId
	 * @return string
	 */
	public function formatDateTime($dateTime, $format, ?string $languageId = null)
	{
		if ($dateTime instanceof \DateTime || $dateTime instanceof Type\Date)
		{
			$timestamp = Type\DateTime::createFromPhp(\DateTime::createFromFormat('Y-m-d H:i:s', $dateTime->format('Y-m-d H:i:s')));
		}
		else
		{
			$timestamp = $dateTime;
		}

		return \formatDate($format, $timestamp, false, $languageId);
	}

	/**
	 * @param \DateTime $from
	 * @param \DateTime|int $toOrDaysCount
	 * @return \DatePeriod
	 * @throws \Exception
	 */
	public function buildDatesIterator(\DateTime $from, $toOrDaysCount)
	{
		$toOrDaysCount = ($toOrDaysCount === 0 ? $from : $toOrDaysCount);
		return new \DatePeriod($from, new \DateInterval('P1D'), $toOrDaysCount);
	}
}

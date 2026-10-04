<?php
namespace Bitrix\Timeman\Helper\Form\Worktime;

use Bitrix\Timeman\Helper\TimeHelper;
use Bitrix\Timeman\Model\User\User;
use Bitrix\Timeman\Model\Worktime\Record\WorktimeRecord;

class RecordFormHelper
{
	private const CODE_BLUE = 'orange';
	private const CODE_RED = 'red';
	private const CODE_GREEN = 'blue';
	private const CODE_GRAY = 'gray';
	/** @var TimeHelper */
	private $timeHelper;

	public function __construct()
	{
		$this->timeHelper = TimeHelper::getInstance();
	}

	/**
	 * @param User $mainUser
	 * @param User|null $oppositeUser
	 * @param \DateTime $firstDateTime
	 * @param \DateTime $secondDateTime
	 */
	public function buildTimeDifferenceHint(User $mainUser, $oppositeUser, $format, $firstDateTime, $secondDateTime = null)
	{
		// Each interval boundary gets its OWN offset: the start edge from resolveDisplayOffset() and the
		// end edge from resolveSecondBoundaryOffset(). Previously the interval reused the start-edge offset
		// for the end edge, so an interval crossing a DST change (e.g. Berlin spring/fall, where
		// START_OFFSET != STOP_OFFSET) rendered the second edge in the wrong offset. For a single moment
		// ($secondDateTime === null) both offsets collapse to the start one, so the output is unchanged.
		$mainFirstOffset = $this->resolveDisplayOffset($mainUser, $firstDateTime);
		$mainSecondOffset = $secondDateTime !== null
			? $this->resolveSecondBoundaryOffset($mainUser, $secondDateTime, $mainFirstOffset)
			: $mainFirstOffset;

		$oppositeFirstOffset = $oppositeUser instanceof User
			? $this->resolveDisplayOffset($oppositeUser, $firstDateTime)
			: null;
		$oppositeSecondOffset = $oppositeUser instanceof User && $secondDateTime !== null
			? $this->resolveSecondBoundaryOffset($oppositeUser, $secondDateTime, $oppositeFirstOffset)
			: $oppositeFirstOffset;

		// Both sides share one offset AND no DST split inside the interval: the two wall-clocks coincide,
		// so the compact label-free form (the historical output) is kept.
		if ($oppositeFirstOffset !== null
			&& $mainFirstOffset === $oppositeFirstOffset
			&& $mainFirstOffset === $mainSecondOffset
			&& $oppositeFirstOffset === $oppositeSecondOffset)
		{
			return $this->formatInterval($firstDateTime, $secondDateTime, $format, $mainFirstOffset, $mainSecondOffset);
		}

		$resultText = $this->renderUserSegment(
			$mainUser, $format, $firstDateTime, $secondDateTime, $mainFirstOffset, $mainSecondOffset
		);
		if (!($oppositeUser instanceof User))
		{
			return $resultText;
		}

		$resultText .= '<br>' . $this->renderUserSegment(
			$oppositeUser, $format, $firstDateTime, $secondDateTime, $oppositeFirstOffset, $oppositeSecondOffset
		);

		return $resultText;
	}

	/**
	 * Renders one user's segment of the hint. When the interval crosses a DST change on this user's side
	 * ($firstOffset !== $secondOffset), each boundary is labelled with its OWN "(UTC ...)" offset so the
	 * reader sees which offset applied where; otherwise the compact single trailing label is kept.
	 *
	 * @param \DateTime|string $firstDateTime
	 * @param \DateTime|string|null $secondDateTime
	 */
	private function renderUserSegment(User $user, $format, $firstDateTime, $secondDateTime, int $firstOffset, int $secondOffset): string
	{
		if ($secondDateTime !== null && $firstOffset !== $secondOffset)
		{
			return $this->formatDateTime($firstDateTime, $format, $firstOffset)
				. ' ' . $this->buildUtcOffsetText($firstOffset, $user)
				. ' - ' . $this->formatDateTime($secondDateTime, $format, $secondOffset)
				. ' ' . $this->buildUtcOffsetText($secondOffset, $user);
		}

		return $this->formatInterval($firstDateTime, $secondDateTime, $format, $firstOffset, $secondOffset)
			. ' ' . $this->buildUtcOffsetText($firstOffset, $user);
	}

	/**
	 * Formats "first[ - second]" with each boundary in its own offset (no "(UTC ...)" label).
	 *
	 * @param \DateTime|string $firstDateTime
	 * @param \DateTime|string|null $secondDateTime
	 */
	private function formatInterval($firstDateTime, $secondDateTime, $format, int $firstOffset, int $secondOffset): string
	{
		$text = $this->formatDateTime($firstDateTime, $format, $firstOffset);
		if ($secondDateTime !== null)
		{
			$text .= ' - ' . $this->formatDateTime($secondDateTime, $format, $secondOffset);
		}

		return $text;
	}

	/**
	 * Resolves the offset for the SECOND interval boundary independently of the first.
	 *
	 * For an unpinned user the offset is derived date-aware from the real IANA zone at the second instant
	 * (so the grid gets DST-correct edges). For a pinned user the per-edge historical snapshot is already
	 * encoded in the passed DateTime's own fixed-offset zone - e.g. buildRecordedStopDateTime() carries
	 * STOP_OFFSET - so it is read back from there rather than forced onto the start snapshot returned by
	 * obtainUtcOffset(). A non-DateTime edge (raw string) keeps the first offset; the string is emitted
	 * verbatim and the offset is unused.
	 *
	 * @param \DateTime|string $secondDateTime
	 */
	private function resolveSecondBoundaryOffset(User $user, $secondDateTime, int $firstOffset): int
	{
		if (!($secondDateTime instanceof \DateTime))
		{
			return $firstOffset;
		}
		if ($user->obtainPinnedUtcOffset() !== null)
		{
			return $secondDateTime->getOffset();
		}

		$userId = (int)$user->getId();
		if ($userId > 0)
		{
			return (int)$this->timeHelper->getOffsetAt($userId, $secondDateTime->getTimestamp());
		}

		return $firstOffset;
	}

	/**
	 * Resolves the UTC offset used to DISPLAY the given instant for the user.
	 *
	 * An explicitly pinned offset (User::defineUtcOffset(), e.g. the record report's historical
	 * START_OFFSET "as it was recorded", or the viewer offset pinned date-aware by the caller) takes
	 * precedence and is returned as-is. When no offset was pinned (obtainPinnedUtcOffset() === null),
	 * we derive the date-aware offset from the user's real IANA zone at the instant being shown
	 * (ALG-02), so consumers without a pinned offset (e.g. the worktime grid) get DST-correct values
	 * rather than an arbitrary call-moment offset.
	 *
	 * @param User $user
	 * @param \DateTime $dateTime instant being displayed
	 * @return int
	 */
	private function resolveDisplayOffset(User $user, $dateTime)
	{
		// Explicit pinned snapshot (historical START_OFFSET or date-aware viewer offset set by caller)
		// takes precedence; no heuristic comparison needed.
		if ($user->obtainPinnedUtcOffset() !== null)
		{
			return (int)$user->obtainUtcOffset();
		}

		$userId = (int)$user->getId();
		if ($dateTime instanceof \DateTime && $userId > 0)
		{
			return (int)$this->timeHelper->getOffsetAt($userId, $dateTime->getTimestamp());
		}

		return 0;
	}

	/**
	 * @param \DateTime|string $dateTime
	 * @param $format
	 * @param $offset
	 * @return string
	 */
	private function formatDateTime($dateTime, $format, $offset)
	{
		if ($dateTime instanceof \DateTime)
		{
			$dateTimeNewOffset = clone $dateTime;
			$dateTimeNewOffset->setTimezone($this->timeHelper->createTimezoneByOffset($offset));
			return $this->timeHelper->formatDateTime($dateTimeNewOffset, $format);
		}
		return is_string($dateTime) ? $dateTime : '';
	}

	/**
	 * Renders the "(UTC +/-HH:MM zoneName)" label for a resolved display offset. The zone name is taken from
	 * resolveDisplayZoneName(), which is already normalized - the raw persisted TIME_ZONE is never emitted.
	 *
	 * @param int $offset resolved display offset (see resolveDisplayOffset())
	 * @param User $user
	 */
	private function buildUtcOffsetText($offset, User $user)
	{
		$offset = (int)$offset;
		if ($offset === 0)
		{
			return '(UTC)';
		}

		$name = $this->resolveDisplayZoneName($user);
		if ($name !== '')
		{
			$name = ' ' . $name;
		}

		return '(UTC ' . $this->timeHelper->formatSignedOffset($offset) . ')' . $name;
	}

	/**
	 * Resolves the IANA zone name shown next to the offset, guaranteeing it is normalized.
	 *
	 * An explicitly pinned name (defineTimezoneName(), e.g. the record report's normalized viewer zone, or
	 * an empty string for a synthetic historical START_OFFSET/STOP_OFFSET) is authoritative and used
	 * verbatim - the caller already normalized it. When nothing was pinned we must NOT surface the raw
	 * persisted TIME_ZONE (obtainTimeZone()), which may be empty or invalid (e.g. "Mars/Phobos") and would
	 * diverge from the report; instead we resolve the user's effective IANA zone via the shared resolver
	 * (portal/server-default fallback). Grid callers seed the request-scoped zone cache beforehand, so this
	 * resolution hits the cache and adds no per-row query.
	 *
	 * @param User $user
	 * @return string normalized IANA zone id, or '' (pinned-empty synthetic offset / unresolvable user)
	 */
	private function resolveDisplayZoneName(User $user): string
	{
		$pinnedName = $user->obtainPinnedTimezoneName();
		if ($pinnedName !== null)
		{
			return $pinnedName;
		}

		$userId = (int)$user->getId();
		if ($userId > 0)
		{
			return $this->timeHelper->resolveEffectiveTimeZoneId($userId);
		}

		return '';
	}

	/**
	 * @param $violations
	 * @param $notices
	 * @param WorktimeRecord $record
	 * @return string
	 */
	public function getViolationCode($violations, $notices, $record)
	{
		if (!$record)
		{
			return '';
		}
		$hasEditedViolations = !empty($violations);
		$hasOtherViolations = !empty($notices);
		if (!$hasEditedViolations && $hasOtherViolations)
		{
			return static::CODE_GRAY;
		}
		elseif ($hasEditedViolations && $record->isApproved() && !$hasOtherViolations)
		{
			return static::CODE_GREEN;
		}
		elseif ($hasEditedViolations && !$record->isApproved())
		{
			return static::CODE_RED;
		}
		elseif ($hasEditedViolations && $record->isApproved() && $hasOtherViolations)
		{
			return static::CODE_BLUE;
		}
		return '';
	}

	public function getCssClassForViolations($violations, $notices, $record)
	{
		switch ($this->getViolationCode($violations, $notices, $record))
		{
			case static::CODE_GRAY:
				return 'timeman-record-violation-icon-notice';
			case static::CODE_GREEN:
				return 'timeman-record-violation-icon-confirmed';
			case static::CODE_RED:
				return 'timeman-record-violation-icon-warning';
			case static::CODE_BLUE:
				return 'timeman-record-violation-icon-alert';
		}
		return '';
	}
}

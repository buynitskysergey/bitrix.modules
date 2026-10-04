<?php
namespace Bitrix\Timeman\Model\Schedule\Shift;

use Bitrix\Timeman\Helper\TimeDictionary;
use Bitrix\Timeman\Helper\TimeHelper;
use Bitrix\Timeman\Model\Schedule\Schedule;
use Bitrix\Timeman\Model\Schedule\ShiftPlan\ShiftPlan;

class Shift extends EO_Shift
{
	public static function create($scheduleId, $name, $start, $end, $breakDuration = null, $workDays = null)
	{
		$shift = new static($setDefaultValues = false);
		$shift->setScheduleId($scheduleId);
		$shift->setName($name);
		$shift->setWorkTimeStart($start);
		$shift->setWorkTimeEnd($end);
		$shift->setWorkDays($workDays);
		if ($breakDuration !== null)
		{
			$shift->setBreakDuration($breakDuration);
		}
		return $shift;
	}

	public static function isDateInShiftWorkDays($dateTime, $shift)
	{
		return $shift && in_array(TimeHelper::getInstance()->getDayOfWeek($dateTime), array_map('intval', str_split($shift['WORK_DAYS'])), true);
	}

	public function edit($name, $startTime, $endTime, $breakDuration, $workdays)
	{
		$this->setName($name);
		$this->setWorkTimeStart($startTime);
		$this->setWorkTimeEnd($endTime);
		$this->setBreakDuration($breakDuration);
		$this->setWorkDays($workdays);
	}

	public function getDuration()
	{
		$duration = $this->getWorkTimeEnd() - $this->getWorkTimeStart();
		if ($duration < 0)
		{
			$duration = 24 * TimeDictionary::SECONDS_PER_HOUR - $this->getWorkTimeStart() + $this->getWorkTimeEnd();
		}
		return $duration;
	}

	public function getStartHours()
	{
		return $this->getHours($this->getWorkTimeStart());
	}

	public function getEndHours()
	{
		return $this->getHours($this->getWorkTimeEnd());
	}

	private function getHours($secs)
	{
		return TimeHelper::getInstance()->getHours($secs);
	}

	public function getStartMinutes()
	{
		return $this->getMinutes($this->getWorkTimeStart());
	}

	public function getEndMinutes()
	{
		return $this->getMinutes($this->getWorkTimeEnd());
	}

	private function getMinutes($secs)
	{
		return TimeHelper::getInstance()->getMinutes($secs);
	}

	public function getStartSeconds()
	{
		return $this->getSeconds($this->getWorkTimeStart());
	}

	public function getEndSeconds()
	{
		return $this->getSeconds($this->getWorkTimeEnd());
	}

	private function getSeconds($secs)
	{
		return TimeHelper::getInstance()->getSeconds($secs);
	}

	/**
	 * @return Schedule|null
	 */
	public function obtainSchedule()
	{
		try
		{
			return $this->get('SCHEDULE');
		}
		catch (\Exception $exc)
		{
			return null;
		}
	}

	public function isForTime($seconds, $offset = 0)
	{
		if ($offset < 0)
		{
			$offset = 0;
		}
		$allowedStart = $this->normalizeSeconds($this->getWorkTimeStart() - $offset);
		if ($allowedStart <= $this->getWorkTimeEnd())
		{
			return $seconds >= $allowedStart && $seconds <= $this->getWorkTimeEnd();
		}
		if ($seconds >= $allowedStart && $seconds <= TimeDictionary::SECONDS_PER_DAY)
		{
			return true;
		}
		if ($seconds >= 0 && $seconds <= $this->getWorkTimeEnd())
		{
			return true;
		}

		return false;
	}

	public function isForWeekDay($weekDay)
	{
		return in_array(
			(int) $weekDay,
			array_map('intval', str_split($this->getWorkDays() ?? '')),
			true
		);
	}

	/**
	 * @param ShiftPlan $shiftPlan
	 * @return \DateTime
	 */
	public function buildUtcEndByShiftplan($shiftPlan)
	{
		return $this->buildUtcEndByUserId(
			$shiftPlan->getUserId(),
			$shiftPlan->getDateAssignedUtc()
		);
	}

	/**
	 * @param ShiftPlan $shiftPlan
	 * @return \DateTime
	 */
	public function buildUtcStartByShiftplan($shiftPlan)
	{
		return $this->buildUtcStartByUserId(
			$shiftPlan->getUserId(),
			$shiftPlan->getDateAssignedUtc()
		);
	}

	/**
	 * Absolute (UTC) instant of the shift start: the WORK_TIME_START wall-time on the shift's CALENDAR
	 * date, resolved date-aware in the employee's real IANA zone (ALG-02). DATE_ASSIGNED is stored as
	 * UTC midnight, so only its calendar date ('Y-m-d') is used; the offset is taken at the local shift
	 * start, NOT at UTC midnight (on a DST-transition date these differ).
	 *
	 * @param int $userId
	 * @param \DateTime $shiftDateTime carries the shift's calendar date (DATE_ASSIGNED, UTC midnight)
	 * @return \DateTime
	 */
	public function buildUtcStartByUserId($userId, $shiftDateTime)
	{
		$shiftStartTimestamp = TimeHelper::getInstance()->buildTimestampFromWallTime(
			(int)$userId,
			$shiftDateTime->format('Y-m-d'),
			$this->getWorkTimeStart()
		);
		return new \DateTime('@' . $shiftStartTimestamp);
	}

	/**
	 * Absolute (UTC) instant of the shift END, symmetric to buildUtcStartByUserId(): the WORK_TIME_END
	 * wall-time resolved date-aware in the employee's real IANA zone on the shift's CALENDAR date.
	 * For an overnight shift (WORK_TIME_END <= WORK_TIME_START) the end wall-time belongs to the NEXT local
	 * calendar date.
	 *
	 * The end is built from wall-time, NOT as start + getDuration() elapsed seconds: across a DST transition
	 * inside the shift the elapsed length differs from the wall difference (WORK_TIME_END - WORK_TIME_START),
	 * so start + duration would land the auto-close/violation boundary an hour off. Only the calendar date of
	 * $shiftDateTime is used (like buildUtcStartByUserId); the offset is taken at the local WORK_TIME_END,
	 * not at UTC midnight of DATE_ASSIGNED.
	 *
	 * @param int $userId
	 * @param \DateTime $shiftDateTime carries the shift's calendar date (DATE_ASSIGNED, UTC midnight)
	 * @return \DateTime
	 */
	public function buildUtcEndByUserId($userId, $shiftDateTime)
	{
		$endDate = $shiftDateTime->format('Y-m-d');
		if ($this->getWorkTimeEnd() <= $this->getWorkTimeStart())
		{
			$endDate = (new \DateTime($endDate . ' 00:00:00', new \DateTimeZone('UTC')))
				->add(new \DateInterval('P1D'))
				->format('Y-m-d');
		}
		$shiftEndTimestamp = TimeHelper::getInstance()->buildTimestampFromWallTime(
			(int)$userId,
			$endDate,
			$this->getWorkTimeEnd()
		);
		return new \DateTime('@' . $shiftEndTimestamp);
	}

	private function normalizeSeconds($seconds)
	{
		return TimeHelper::getInstance()->normalizeSeconds($seconds);
	}

	public function isDeleted()
	{
		return $this->getDeleted();
	}

	public function isActive()
	{
		return !$this->isDeleted();
	}
}

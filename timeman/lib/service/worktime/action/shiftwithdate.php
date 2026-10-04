<?php
namespace Bitrix\Timeman\Service\Worktime\Action;

use Bitrix\Main\ArgumentException;
use Bitrix\Timeman\Helper\TimeDictionary;
use Bitrix\Timeman\Helper\TimeHelper;
use Bitrix\Timeman\Model\Schedule\Schedule;
use Bitrix\Timeman\Model\Schedule\Shift\Shift;

class ShiftWithDate
{
	/** @var Shift */
	private $shift;
	/** @var \DateTime */
	private $dateTimeStart;
	/** @var Schedule */
	private $schedule;
	/** @var \DateTime */
	private $dateTimeEnd;

	public function __construct(Shift $shift, Schedule $schedule, \DateTime $dateTimeStart, ?int $userId = null)
	{
		$this->shift = $shift;
		$this->schedule = $schedule;
		if ($this->schedule->isFlextime())
		{
			throw new ArgumentException('Wrong argument, Flexible schedules do not have shifts');
		}
		$this->dateTimeStart = clone $dateTimeStart;
		TimeHelper::getInstance()->setTimeFromSeconds($this->dateTimeStart, $this->shift->getWorkTimeStart());
		$this->dateTimeEnd = $this->buildDateTimeEnd($userId);
	}

	/**
	 * End of the shift as the WORK_TIME_END wall-time on the shift's calendar date, NOT dateTimeStart plus
	 * getDuration() elapsed seconds (the two diverge across a DST transition inside the shift, which would
	 * shift the auto-close/violation stop by an hour).
	 *
	 * With a userId the absolute instant is resolved date-aware in the employee's real IANA zone via
	 * Shift::buildUtcEndByUserId() and then presented in the same zone as dateTimeStart, so every consumer
	 * reading getDateTimeEnd()->getTimestamp() gets the DST-correct instant while the observable wall-time is
	 * preserved for the common (non-DST) case. Without a userId (no user context) the end is the WORK_TIME_END
	 * wall-time in the zone dateTimeStart already carries; in a fixed-offset zone that is identical to the
	 * legacy start + duration, so no behaviour changes where the real zone is unknown.
	 */
	private function buildDateTimeEnd(?int $userId): \DateTime
	{
		$dateTimeEnd = clone $this->dateTimeStart;
		if ($userId !== null)
		{
			$dateTimeEnd->setTimestamp(
				$this->shift->buildUtcEndByUserId($userId, $this->dateTimeStart)->getTimestamp()
			);
			return $dateTimeEnd;
		}

		if ($this->shift->getWorkTimeEnd() <= $this->shift->getWorkTimeStart())
		{
			$dateTimeEnd->add(new \DateInterval('P1D'));
		}
		TimeHelper::getInstance()->setTimeFromSeconds($dateTimeEnd, $this->shift->getWorkTimeEnd());

		return $dateTimeEnd;
	}

	public function isEligibleToStart(\DateTime $userDateTime)
	{
		if ($userDateTime->getTimestamp() >= $this->dateTimeStart->getTimestamp() - $this->getMaxStartOffset()
			&& $userDateTime->getTimestamp() <= $this->dateTimeEnd->getTimestamp() + $this->getMaxEndOffset()
		)
		{
			return true;
		}
		return false;
	}

	private function getMaxStartOffset()
	{
		if ($this->schedule->isFixed())
		{
			return $this->shift->getDuration() / 2;
		}
		if ($this->schedule->isShifted())
		{
			return $this->schedule->getAllowedMaxShiftStartOffset();
		}
		return 0;
	}

	private function getMaxEndOffset()
	{
		if ($this->schedule->isFixed())
		{
			return $this->shift->getDuration() / 2;
		}
		if ($this->schedule->isShifted())
		{
			return TimeDictionary::SECONDS_PER_HOUR;
		}
		return 0;
	}

	public function isEqualsTo(?ShiftWithDate $otherShiftWithDate)
	{
		if (!$otherShiftWithDate)
		{
			return false;
		}
		return $this->getShift()->getId() === $otherShiftWithDate->getShift()->getId()
			   &&
			   $this->getDateTimeStart()->getTimestamp() === $otherShiftWithDate->getDateTimeStart()->getTimestamp();
	}

	public function endedByTime(\DateTime $userNowDateTime)
	{
		return $userNowDateTime->getTimestamp() > $this->getDateTimeEnd()->getTimestamp() + $this->getMaxEndOffset();
	}

	public function getShift()
	{
		return $this->shift;
	}

	public function getDateTimeEnd()
	{
		return $this->dateTimeEnd;
	}

	public function getDateTimeStart()
	{
		return $this->dateTimeStart;
	}

	/**
	 * @return Schedule
	 */
	public function getSchedule(): Schedule
	{
		return $this->schedule;
	}
}
<?php
namespace Bitrix\Timeman\Model\Worktime\Record;

use Bitrix\Main\Type\DateTime;
use Bitrix\Timeman\Form\Worktime\WorktimeRecordForm;
use Bitrix\Timeman\Helper\TimeHelper;
use Bitrix\Timeman\Helper\UserHelper;
use Bitrix\Timeman\Model\Schedule\Schedule;
use Bitrix\Timeman\Model\Schedule\Shift\Shift;
use Bitrix\Timeman\Model\Worktime\EventLog\WorktimeEvent;
use Bitrix\Timeman\Model\Worktime\EventLog\WorktimeEventCollection;
use Bitrix\Timeman\Model\Worktime\EventLog\WorktimeEventTable;
use Bitrix\Timeman\Model\Worktime\Report\EO_WorktimeReport_Collection;
use Bitrix\Timeman\Model\Worktime\Report\WorktimeReportTable;

class WorktimeRecord extends EO_WorktimeRecord
{
	/** @var TimeHelper */
	private $timeHelper;
	/** @var Schedule */
	private $schedule;
	/** @var Shift */
	private $shift;
	/*** @var EO_WorktimeReport_Collection */
	private $reports;
	private $worktimeEvents;

	/**
	 * @param WorktimeRecordForm $recordForm
	 * @param $userId
	 * @param $recordedStartTimestamp
	 * @return WorktimeRecord
	 */
	public static function startWork($recordForm, $recordStartUtcTimestamp = null, $userId = null)
	{
		$userId = $userId === null ? $recordForm->userId : $userId;
		$record = new static(true);
		$record->setScheduleId($recordForm->scheduleId);
		$record->setShiftId($recordForm->shiftId);
		$record->setUserId($userId);

		$record->setRecordedStartTimestamp($recordStartUtcTimestamp);
		if ($recordStartUtcTimestamp === null)
		{
			$recordStartFromForm = $recordForm->buildStartTimestampBySecondsAndDate($record->getUserId());
			if ($recordStartFromForm)
			{
				$record->setRecordedStartTimestamp($recordStartFromForm);
			}
			else
			{
				$record->setRecordedStartTimestamp($record->getTimeHelper()->getUtcNowTimestamp());
			}
		}
		// defineStartTime() derives START_OFFSET date-aware from the employee's real IANA zone at the
		// absolute start instant (NOT getUserUtcOffset() "as of now"), plus DATE_START/TIME_START.
		$record->defineStartTime($record->getRecordedStartTimestamp());
		$record->setIpOpen($recordForm->ipOpen);
		$record->setLatOpen($recordForm->latitudeOpen);
		$record->setLonOpen($recordForm->longitudeOpen);
		$record->setTasks((array)$recordForm->tasks);
		$record->setCurrentStatus(WorktimeRecordTable::STATUS_OPENED);
		$record->setPaused(false);
		$record->setActualStartTimestamp($record->getTimeHelper()->getUtcNowTimestamp());
		$record->setRecordedStopTimestamp(0);
		$record->setStopOffset(0);
		$record->setActualStopTimestamp(0);
		$record->setRecordedDuration(0);
		$record->setActualBreakLength(0);
		$record->setRecordedBreakLength(0);
		$record->approve(true);

		return $record;
	}

	/**
	 * @param WorktimeRecordForm $workRecordForm
	 * @param $recordStopUtcTimestamp
	 * @param null $stopOffset
	 */
	public function stopWork($workRecordForm, $recordStopUtcTimestamp)
	{
		$this->setLatClose($workRecordForm->latitudeClose);
		$this->setLonClose($workRecordForm->longitudeClose);
		$this->setIpClose($workRecordForm->ipClose);
		$actualNowTimestamp = $this->getTimeHelper()->getUtcNowTimestamp();
		$recordStopUtcTimestamp = $recordStopUtcTimestamp ?: $actualNowTimestamp;
		$this->setRecordedStopTimestamp($recordStopUtcTimestamp);
		$this->normalizeBreakLengthForBackdatedStop($recordStopUtcTimestamp);
		$hasFutureStopWhilePaused = $this->hasFutureStopWhilePaused($recordStopUtcTimestamp, $actualNowTimestamp);

		if ($this->isOpened() || $this->isClosed())
		{
			$this->setRecordedDuration($this->calculateDurationSince($this->getRecordedStopTimestamp()));
		}
		elseif ($this->isPaused())
		{
			$breakStopTimestamp = $hasFutureStopWhilePaused ? $actualNowTimestamp : $this->getRecordedStopTimestamp();
			$newBreak = $this->calculateDurationSince($breakStopTimestamp) - $this->getRecordedDuration();
			$this->increaseBreaks($this->clampNonNegative($newBreak));
		}

		if (
			$hasFutureStopWhilePaused
			|| (int)$this->getActualStopTimestamp() === 0
			|| $this->getActualStopTimestamp() === null
		)
		{
			$this->setActualStopTimestamp($actualNowTimestamp);
		}
		$this->setCurrentStatus(WorktimeRecordTable::STATUS_CLOSED);
		$this->setPaused(false);
		$displayedStopTimestamp = $hasFutureStopWhilePaused ? $actualNowTimestamp : $this->getRecordedStopTimestamp();

		// STOP_OFFSET is a date-aware snapshot of the employee's zone at the displayed stop instant,
		// derived for ANY editor (the legacy editedBy null/self asymmetry is removed). An explicitly
		// passed stopOffset is honored ONLY on the trusted system path (auto-close, see
		// AutoCloseWorktimeAgent). stopOffset is a public loadable form field, so a non-system (public
		// edit) request could otherwise smuggle a stale/forged offset; for any non-system path we always
		// re-derive the offset date-aware, ignoring the submitted value.
		if ($workRecordForm->stopOffset === null || !$workRecordForm->isSystem)
		{
			$workRecordForm->stopOffset = $this->getTimeHelper()->getOffsetAt(
				$this->getUserId(),
				(int)$displayedStopTimestamp
			);
		}
		$this->setStopOffset($workRecordForm->stopOffset);

		$this->setDateFinish(DateTime::createFromTimestamp($displayedStopTimestamp));
		$this->setTimeFinish(
			TimeHelper::getInstance()->getSecondsFromDateTime(
				$this->buildRecordedStopDateTime($displayedStopTimestamp)
			)
		);

		$this->setDuration($this->calculateElapsedDuration($displayedStopTimestamp));

		$this->setPaused(false);
	}

	/**
	 * @param WorktimeRecordForm $recordForm
	 */
	public function pauseWork($recordForm)
	{
		$pauseStartUtcTimestamp = $this->getTimeHelper()->getUtcNowTimestamp();

		$this->setRecordedDuration($this->calculateDurationSince($pauseStartUtcTimestamp));

		$this->setCurrentStatus(WorktimeRecordTable::STATUS_PAUSED);

		$this->setIpClose($recordForm->ipClose);
		$this->setLonClose($recordForm->longitudeClose);
		$this->setLatClose($recordForm->latitudeClose);

		$this->setDateFinish(DateTime::createFromTimestamp($pauseStartUtcTimestamp));

		$this->setTimeFinish(
			TimeHelper::getInstance()->getSecondsFromDateTime($this->buildRecordedStartDateTime())
			+ $this->getTimeLeaks()
			+ $this->getRecordedDuration()
		);

		$this->setPaused(true);
	}

	public function continueWork()
	{
		$continueUtcTimestamp = $this->getTimeHelper()->getUtcNowTimestamp();

		$this->setDateFinish(null);
		$this->setTimeFinish(null);
		$this->setDuration(0);

		if ($continueUtcTimestamp < $this->getRecordedStopTimestamp())
		{
			if ($this->shouldReduceBreaksOnContinue($continueUtcTimestamp))
			{
				$futureBreak = $this->getRecordedStopTimestamp() - $continueUtcTimestamp;
				$this->setRecordedBreakLength(
					$this->clampNonNegative($this->getRecordedBreakLength() - $futureBreak)
				);
				$this->setActualBreakLength(
					$this->clampNonNegative($this->getActualBreakLength() - $futureBreak)
				);
			}
			else
			{
				$this->setRecordedDuration($this->calculateDurationSince($continueUtcTimestamp));
			}
		}

		$newBreak = $continueUtcTimestamp - $this->getRecordedStartTimestamp() - $this->getRecordedDuration() - $this->getRecordedBreakLength();
		$this->increaseBreaks($this->clampNonNegative($newBreak));

		$this->setStopOffset(0);
		$this->setRecordedStopTimestamp(0);
		$this->setActualStopTimestamp(0);
		$this->setCurrentStatus(WorktimeRecordTable::STATUS_OPENED);
		$this->setPaused(false);
	}

	/**
	 * @param WorktimeRecordForm $workRecordForm
	 * @return $this
	 */
	public function updateByForm($workRecordForm)
	{
		// The entered "hours:minutes" is ALWAYS interpreted in the employee's (getUserId()) real IANA
		// zone on the event date, regardless of who edits. useEmployeesTimezone no longer influences the
		// recorded timestamp (it is a display-only mode now; its real display role lands in P5).
		// Decide whether the boundary really moved by comparing the submitted
		// WALL date/time against the ORIGINAL recorded wall date/time ("as recorded") before reconstructing
		// an absolute instant. This preserves an untouched boundary through both DST folds and gaps.
		if ($this->isWallTimeChanged(
			$this->buildRecordedStartDateTime(),
			$workRecordForm->recordedStartSeconds,
			$workRecordForm->recordedStartDateFormatted
		))
		{
			$recordedStartTimestamp = $workRecordForm->buildStartTimestampBySecondsAndDate(
				$this->getUserId(),
				$this->getRecordedStartTimestamp()
			);
			$this->editStartByForm($recordedStartTimestamp);
		}

		$originalStopDateTime = (int)$this->getRecordedStopTimestamp() > 0
			? $this->buildRecordedStopDateTime()
			: null;
		if ($this->isWallTimeChanged(
			$originalStopDateTime,
			$workRecordForm->recordedStopSeconds,
			$workRecordForm->recordedStopDateFormatted
		))
		{
			$recordedStopTimestamp = $this->buildStopTimestampBySecondsAndDate(
				$workRecordForm->recordedStopSeconds,
				$workRecordForm->recordedStopDateFormatted,
				$this->getUserId()
			);
			$this->editStopByForm($workRecordForm, $recordedStopTimestamp);
		}

		if ($this->isTimeNeedToBeSaved($workRecordForm->recordedBreakLength, $this->getRecordedBreakLength()))
		{
			$this->setRecordedBreakLength($workRecordForm->recordedBreakLength);
			$this->updateDuration(
				$this->getRecordedStopTimestamp() > 0
					? $this->getRecordedStopTimestamp()
					: $this->getTimeHelper()->getUtcNowTimestamp()
			);
		}
		return $this;
	}

	private function editStartByForm($recordedStartTimestamp = null)
	{
		$this->defineStartTime($recordedStartTimestamp);
		$this->updateDuration();
	}

	/**
	 * @return mixed|null
	 */
	public function buildStopTimestampBySecondsAndDate($stopSeconds, $stopFormattedDate, $userIdTimezone)
	{
		$recordedStopTimestamp = null;
		if ($stopSeconds === null || $stopSeconds < 0 || !$userIdTimezone)
		{
			return $recordedStopTimestamp;
		}

		$timeHelper = $this->getTimeHelper();
		if ($stopFormattedDate)
		{
			// Build the absolute stop instant from the wall-time on the chosen calendar date directly in
			// the employee's real IANA zone (date-aware), instead of the legacy server-zone arithmetic.
			$timestampUtcForUserDate = $timeHelper->getTimestampByUserDate($stopFormattedDate, $userIdTimezone);
			if ($timestampUtcForUserDate > 0)
			{
				$stopDate = (new \DateTime('@' . $timestampUtcForUserDate))
					->setTimezone($timeHelper->getUserDateTimeZone($userIdTimezone));
				$recordedStopTimestamp = $timeHelper->buildTimestampFromWallTime(
					$userIdTimezone, $stopDate->format('Y-m-d'), $stopSeconds
				);
			}
		}
		else
		{
			// Overnight without an explicit date: derive the start's wall date in the employee's real
			// zone, then build the stop wall-time on that date (or the next day when stop <= start) in
			// the same real zone, so the +1 day boundary is DST-correct on the event date.
			$startDateTime = (new \DateTime('@' . (int)$this->getRecordedStartTimestamp()))
				->setTimezone($timeHelper->getUserDateTimeZone($userIdTimezone));
			$startSeconds = $timeHelper->getSecondsFromDateTime($startDateTime);
			$eventDate = $startDateTime->format('Y-m-d');
			if ($stopSeconds <= $startSeconds)
			{
				$eventDate = $startDateTime->modify('+1 day')->format('Y-m-d');
			}
			$recordedStopTimestamp = $timeHelper->buildTimestampFromWallTime(
				$userIdTimezone, $eventDate, $stopSeconds
			);
		}
		return $recordedStopTimestamp;
	}

	private function isTimeNeedToBeSaved($formTimestamp, $recordTimestamp)
	{
		return $formTimestamp !== null
			   && abs($formTimestamp - $recordTimestamp) > 59;
	}

	/**
	 * No-op idempotency guard for a recorded start/stop boundary.
	 *
	 * Compares the submitted seconds-of-day and optional date directly against the ORIGINAL recorded wall
	 * date/time reconstructed from the frozen snapshot offset. The comparison happens before rebuilding an
	 * absolute instant, because normalization in an IANA zone can change a repeated or nonexistent wall time.
	 *
	 * The sub-minute tolerance mirrors the legacy isTimeNeedToBeSaved() threshold: the form carries only
	 * minute precision, so a recorded value with a seconds component (e.g. 08:00:37) must not read as an
	 * edit when the user re-submits the same 08:00.
	 *
	 * @param \DateTime|null $originalDateTime recorded wall time "as recorded"
	 * @param int|null $candidateSeconds submitted seconds-of-day, or null when nothing was submitted
	 * @param string|null $candidateFormattedDate submitted calendar date, or null to keep the original date
	 */
	private function isWallTimeChanged(
		?\DateTime $originalDateTime,
		?int $candidateSeconds,
		?string $candidateFormattedDate
	): bool
	{
		if ($candidateSeconds === null)
		{
			return false;
		}
		if ($originalDateTime === null)
		{
			return true;
		}

		$timeHelper = $this->getTimeHelper();
		if (abs($timeHelper->getSecondsFromDateTime($originalDateTime) - $candidateSeconds) > 59)
		{
			return true;
		}
		if ($candidateFormattedDate === null || $candidateFormattedDate === '')
		{
			return false;
		}

		$candidateDateTimestamp = $timeHelper->getTimestampByUserDate(
			$candidateFormattedDate,
			(int)$this->getUserId()
		);
		if ($candidateDateTimestamp <= 0)
		{
			return false;
		}

		$candidateDateTime = (new \DateTime('@' . $candidateDateTimestamp))
			->setTimezone($timeHelper->getUserDateTimeZone((int)$this->getUserId()));

		return $originalDateTime->format('Y-m-d') !== $candidateDateTime->format('Y-m-d');
	}

	/**
	 * @param WorktimeRecordForm $workRecordForm
	 */
	private function editStopByForm($workRecordForm, $recordedStopTimestamp = null)
	{
		$this->stopWork($workRecordForm, $recordedStopTimestamp);
	}

	private function updateDuration($endTimestamp = null)
	{
		if ($endTimestamp === null)
		{
			$endTimestamp = $this->getRecordedStopTimestamp();
		}
		$elapsedStopTimestamp = null;
		if ($endTimestamp > 0)
		{
			$this->setRecordedDuration($this->calculateDurationSince($endTimestamp));
			$elapsedStopTimestamp = (int)$endTimestamp;
		}
		elseif ($this->isPaused())
		{
			$pauseTimestamp = $this->resolvePausedTimestamp();
			if ($pauseTimestamp !== null)
			{
				$this->setRecordedDuration($this->calculateDurationSince($pauseTimestamp));
				$elapsedStopTimestamp = (int)$pauseTimestamp;
			}
		}

		if ($elapsedStopTimestamp !== null && (int)$this->getRecordedStartTimestamp() > 0)
		{
			// DURATION canon (ALG-03): elapsed from absolute instants, offset-agnostic.
			$this->setDuration($this->calculateElapsedDuration($elapsedStopTimestamp));
		}
		else
		{
			// Fallback when no absolute stop instant is available (only seconds-of-day are known).
			// correctDuration() (not clampDaySeconds) is used here DELIBERATELY: for entries without an
			// absolute stop timestamp the only hint is wall-clock TIME_FINISH - TIME_START. When the stop
			// crosses midnight (overnight, TIME_FINISH < TIME_START) this difference is negative, and
			// correctDuration's +86400 wrap produces a plausible elapsed value. This is the INTENDED legacy
			// semantics for that path. In contrast, calculateElapsedDuration() uses clampDaySeconds (negative
			// -> 0) because it works from absolute instants where a negative result is a data error, not an
			// overnight signal.
			$this->setDuration(
				$this->correctDuration(
					$this->getTimeFinish() - $this->getTimeStart() - $this->getRecordedBreakLength()
				)
			);
		}
	}

	private function getTimeHelper()
	{
		if (!$this->timeHelper)
		{
			$this->timeHelper = TimeHelper::getInstance();
		}
		return $this->timeHelper;
	}

	public function isActive()
	{
		return $this->isOpened() || $this->isPaused();
	}

	public function isOpened()
	{
		return $this->getCurrentStatus() === WorktimeRecordTable::STATUS_OPENED;
	}

	public static function isRecordClosed($record)
	{
		$recordObject = $record;
		if (!($recordObject instanceof WorktimeRecord))
		{
			if (!is_array($record))
			{
				return false;
			}
			$recordObject = static::wakeUpRecord($record);
		}
		return $recordObject->isClosed();
	}

	public static function isRecordPaused($record)
	{
		$recordObject = $record;
		if (!($recordObject instanceof WorktimeRecord))
		{
			if (!is_array($record))
			{
				return false;
			}
			$recordObject = static::wakeUpRecord($record);
		}
		return $recordObject->isPaused();
	}

	public static function isRecordOpened($record)
	{
		if (!is_array($record))
		{
			return false;
		}
		$recordObject = $record;
		if (!($recordObject instanceof WorktimeRecord))
		{
			$recordObject = static::wakeUpRecord($record);
		}
		return $recordObject->isOpened();
	}

	public function isClosed()
	{
		return $this->getCurrentStatus() === WorktimeRecordTable::STATUS_CLOSED;
	}

	public function isPaused()
	{
		return $this->getCurrentStatus() === WorktimeRecordTable::STATUS_PAUSED;
	}

	public function isApproved()
	{
		return $this->getApproved() === true;
	}

	/**
	 * @return \Bitrix\Timeman\Model\Worktime\EventLog\WorktimeEvent|null
	 */
	public function obtainEventByType()
	{
		$args = func_get_args();
		foreach ($args as $eventType)
		{
			$events = $this->obtainWorktimeEvents();
			foreach ($events as $event)
			{
				$type = $event->getEventType();
				if ($event->getEventType() === WorktimeEventTable::EVENT_TYPE_STOP_WITH_ANOTHER_TIME)
				{
					$type = WorktimeEventTable::EVENT_TYPE_EDIT_STOP;
				}
				if ($event->getEventType() === WorktimeEventTable::EVENT_TYPE_START_WITH_ANOTHER_TIME)
				{
					$type = WorktimeEventTable::EVENT_TYPE_EDIT_START;
				}
				if ($type === $eventType)
				{
					return $event;
				}
			}
		}
		$reports = $this->obtainReports();
		if (empty($reports))
		{
			return null;
		}
		$map = [
			WorktimeEventTable::EVENT_TYPE_EDIT_START => WorktimeReportTable::REPORT_TYPE_REPORT_OPEN,
			WorktimeEventTable::EVENT_TYPE_EDIT_STOP => WorktimeReportTable::REPORT_TYPE_REPORT_CLOSE,
			WorktimeEventTable::EVENT_TYPE_EDIT_BREAK_LENGTH => WorktimeReportTable::REPORT_TYPE_REPORT_DURATION,
		];
		foreach ($args as $realEventType)
		{
			foreach ($map as $neededEventType => $neededReportType)
			{
				if ($neededEventType == $realEventType)
				{
					foreach ($reports as $report)
					{
						if ($report->getReportType() == $neededReportType)
						{
							$entity = new WorktimeEvent(false);
							$entity->setReason($report->getReport());
							$entity->setActualTimestamp($report->getTimestampX()->getTimestamp());
							return $entity;
						}
					}
				}
			}
		}
		return null;
	}

	public function defineWorktimeEvents($worktimeEvents)
	{
		$this->worktimeEvents = $worktimeEvents;
	}

	/**
	 * @return WorktimeEventCollection
	 */
	public function obtainWorktimeEvents()
	{
		if ($this->worktimeEvents instanceof WorktimeEventCollection)
		{
			return $this->worktimeEvents;
		}
		try
		{
			$worktimeEvents = $this->get('WORKTIME_EVENTS');

			return (
				($worktimeEvents instanceof WorktimeEventCollection)
					? $worktimeEvents
					: new WorktimeEventCollection()
			);
		}
		catch (\Exception $exc)
		{
			return new WorktimeEventCollection();
		}
	}

	public function isFirstStop(): bool
	{
		foreach ($this->obtainWorktimeEvents() as $event)
		{
			if (in_array($event->getEventType(), [
				WorktimeEventTable::EVENT_TYPE_STOP,
				WorktimeEventTable::EVENT_TYPE_EDIT_STOP,
				WorktimeEventTable::EVENT_TYPE_STOP_WITH_ANOTHER_TIME,
			], true))
			{
				return false;
			}
		}

		return true;
	}

	private function calculateDurationSince($endTimestamp)
	{
		$raw = ($endTimestamp - $this->getRecordedStartTimestamp()) - $this->getRecordedBreakLength();

		return $this->clampDaySeconds((int)$raw);
	}

	/**
	 * DURATION canonical contract (ALG-03): real elapsed working time computed from the absolute
	 * RECORDED_*_TIMESTAMP instants minus breaks, NOT the wall-clock (TIME_FINISH - TIME_START)
	 * difference. When START_OFFSET != STOP_OFFSET (a DST shift inside the day) the wall-clock formula
	 * diverges from elapsed by (STOP_OFFSET - START_OFFSET); the elapsed base is offset-agnostic.
	 * Overnight is handled implicitly by absolute instants (a genuine overnight day yields a large
	 * positive elapsed), so the legacy wall-clock +86400 correction must NOT be applied here: a negative
	 * elapsed (break > elapsed) is a clamp-to-zero case, not an overnight wrap. We clamp via
	 * clampDaySeconds() (negative -> 0, > 86400 -> 86400).
	 *
	 * @param int $stopTimestamp absolute UTC stop instant (displayed stop, equals RECORDED_STOP_TIMESTAMP
	 *                           except for future-stop-while-paused where it is the actual now)
	 * @return int
	 */
	private function calculateElapsedDuration(int $stopTimestamp): int
	{
		$elapsed = $stopTimestamp - (int)$this->getRecordedStartTimestamp() - (int)$this->getRecordedBreakLength();

		return $this->clampDaySeconds((int)$elapsed);
	}

	public function approve(bool $approved = true)
	{
		$wasApproved = $this->isApproved();
		$this->setApproved($approved);
		$this->setActive($this->getApproved());
		if (!$wasApproved && $this->isApproved() && UserHelper::getCurrentUserId() > 0)
		{
			$this->setApprovedBy(UserHelper::getCurrentUserId());
		}
		if (!$approved)
		{
			$this->setApprovedBy(0);
		}
	}

	public static function wakeUpRecord($record)
	{
		if ($record instanceof WorktimeRecord)
		{
			return $record;
		}
		$result = [];
		foreach (\Bitrix\Timeman\Model\Worktime\Record\WorktimeRecordTable::getMap() as $item)
		{
			/** @var \Bitrix\Main\ORM\Fields\Field $item */
			foreach ($record as $name => $field)
			{
				if ($name === $item->getName())
				{
					$result[$name] = $field;
					break;
				}
			}
		}
		return WorktimeRecordTable::wakeUpObject($result);
	}

	public function defineReports($reports)
	{
		$this->reports = $reports;
	}

	public function obtainReports()
	{
		if ($this->reports instanceof EO_WorktimeReport_Collection)
		{
			return $this->reports;
		}
		try
		{
			return $this->get('REPORTS');
		}
		catch (\Exception $exc)
		{
			return [];
		}
	}

	/**
	 * @return Shift|null
	 */
	public function obtainShift()
	{
		if ($this->shift instanceof Shift)
		{
			return $this->shift;
		}
		try
		{
			return $this->get('SHIFT') ? $this->get('SHIFT') : null;
		}
		catch (\Exception $exc)
		{
			return null;
		}
	}

	/**
	 * @return Schedule|null
	 */
	public function obtainSchedule()
	{
		if ($this->schedule instanceof Schedule)
		{
			return $this->schedule;
		}
		try
		{
			return $this->get('SCHEDULE') ? $this->get('SCHEDULE') : null;
		}
		catch (\Exception $exc)
		{
			return null;
		}
	}

	public function buildRecordedStopDateTime($recordedStopTimestamp = null)
	{
		if ($recordedStopTimestamp === null)
		{
			$recordedStopTimestamp = $this->getRecordedStopTimestamp();
		}
		// Reconstruction "as it was recorded": real instant + frozen STOP_OFFSET snapshot (historical fact).
		return $this->getTimeHelper()->reconstructHistoricalDateTime(
			(int)$recordedStopTimestamp,
			(int)$this->getStopOffset()
		);
	}

	public function buildRecordedStartDateTime(): ?\DateTime
	{
		// Reconstruction "as it was recorded": real instant + frozen START_OFFSET snapshot (historical fact).
		return $this->getTimeHelper()->reconstructHistoricalDateTime(
			(int)$this->getRecordedStartTimestamp(),
			(int)$this->getStartOffset()
		);
	}

	private function defineStartTime($recordedStartTimestamp)
	{
		$this->setRecordedStartTimestamp($recordedStartTimestamp);
		// START_OFFSET is a date-aware derivative of the employee's real IANA zone at the start instant;
		// it is recalculated whenever the start moves (removes the legacy "frozen on edit" asymmetry).
		$this->setStartOffset(
			$this->getTimeHelper()->getOffsetAt($this->getUserId(), (int)$this->getRecordedStartTimestamp())
		);
		$this->setDateStart(DateTime::createFromTimestamp($this->getRecordedStartTimestamp()));
		$this->setTimeStart(TimeHelper::getInstance()->getSecondsFromDateTime($this->buildRecordedStartDateTime()));
	}

	public function calculateCurrentDuration()
	{
		$endTime = $this->getRecordedStopTimestamp();
		if (!$endTime)
		{
			$endTime = TimeHelper::getInstance()->getUtcNowTimestamp();
		}
		$raw = $endTime - $this->calculateCurrentBreakLength() - $this->getRecordedStartTimestamp();

		return $this->clampDaySeconds((int)$raw);
	}

	public function calculateCurrentBreakLength()
	{
		$break = $this->getRecordedBreakLength();
		if ($this->isPaused())
		{
			$break = TimeHelper::getInstance()->getUtcNowTimestamp() - $this->getRecordedDuration() - $this->getRecordedStartTimestamp();
		}

		return $this->clampDaySeconds((int)$break);
	}

	/**
	 * Absolute instant (UTC seconds) of the DISPLAYED finish, derived from absolute columns only by
	 * inverting DURATION: start + working seconds + accrued breaks. Callers must know the record has a
	 * finish (closed or paused).
	 *
	 * Neither raw column works as that anchor: RECORDED_STOP_TIMESTAMP/ACTUAL_STOP_TIMESTAMP are 0 while
	 * paused and hold a future instant for an edited stop, and getDateFinish() is viewer-shifted once the
	 * record is woken up from the legacy compatibility query (DateToCharFunction adds the site offset).
	 *
	 * The result reproduces DATE_FINISH for records stored consistently, but DURATION is not always its
	 * exact inverse: editing the break of a record closed with a future stop recomputes DURATION against
	 * that future stop, and a break longer than the elapsed day clamps DURATION to 0. Both cases yield an
	 * instant in the future.
	 */
	public function buildDisplayedStopTimestamp(): int
	{
		$workingSeconds = $this->isPaused()
			? (int)$this->getRecordedDuration()
			: (int)$this->getDuration();

		return (int)$this->getRecordedStartTimestamp() + $workingSeconds + (int)$this->getRecordedBreakLength();
	}

	public function isRecordedBreakLengthChanged()
	{
		return $this->isTimeLeaksChanged();
	}

	public function getRecordedBreakLength()
	{
		return $this->getTimeLeaks();
	}

	public function setRecordedBreakLength($length)
	{
		$this->setTimeLeaks($length);
		return $this;
	}

	public function setTimeLeaks($timeLeaks)
	{
		parent::setTimeLeaks($this->clampDaySeconds((int)$timeLeaks));

		return $this;
	}

	public function setRecordedDuration($recordedDuration)
	{
		parent::setRecordedDuration($this->clampDaySeconds((int)$recordedDuration));

		return $this;
	}

	public function defineSchedule(Schedule $schedule)
	{
		$this->schedule = $schedule;
	}

	public function defineShift(Shift $shift)
	{
		$this->shift = $shift;
	}

	private function increaseBreaks($newBreak)
	{
		$newBreak = $this->clampNonNegative((int)$newBreak);
		$this->setRecordedBreakLength($this->getRecordedBreakLength() + $newBreak);
		$this->setActualBreakLength($this->clampDaySeconds((int)($this->getActualBreakLength() + $newBreak)));
	}

	private function normalizeBreakLengthForBackdatedStop(int $recordStopUtcTimestamp): void
	{
		$breakDurationAfterBackdatedStop = 0;
		$openBreakStartTimestamp = null;

		foreach ($this->getWorktimeEventsOrderedByActualTimestamp() as $worktimeEvent)
		{
			$eventType = $worktimeEvent->getEventType();
			if ($this->isBreakStartingEventType($eventType))
			{
				$openBreakStartTimestamp = $this->resolveBreakStartTimestamp($worktimeEvent);

				continue;
			}

			if ($eventType !== WorktimeEventTable::EVENT_TYPE_CONTINUE || $openBreakStartTimestamp === null)
			{
				continue;
			}

			$continueTimestamp = $worktimeEvent->getActualTimestamp();
			$isBackdatedStopBeforeContinue = $continueTimestamp > 0 && $recordStopUtcTimestamp < $continueTimestamp;
			if ($isBackdatedStopBeforeContinue)
			{
				$breakDurationAfterBackdatedStop += max($continueTimestamp - $openBreakStartTimestamp, 0);
			}

			$openBreakStartTimestamp = null;
		}

		if ($breakDurationAfterBackdatedStop <= 0)
		{
			return;
		}

		$this->setRecordedBreakLength(
			$this->clampNonNegative($this->getRecordedBreakLength() - $breakDurationAfterBackdatedStop)
		);

		$this->setActualBreakLength(
			$this->clampNonNegative($this->getActualBreakLength() - $breakDurationAfterBackdatedStop)
		);
	}

	private function getWorktimeEventsOrderedByActualTimestamp(): array
	{
		$worktimeEvents = iterator_to_array($this->obtainWorktimeEvents());
		usort($worktimeEvents, static function ($left, $right) {
			$timestampCompare = $left->getActualTimestamp() <=> $right->getActualTimestamp();
			if ($timestampCompare !== 0)
			{
				return $timestampCompare;
			}

			return $left->getId() <=> $right->getId();
		});

		return $worktimeEvents;
	}

	private function isBreakStartingEventType(string $eventType): bool
	{
		return in_array($eventType, [
			WorktimeEventTable::EVENT_TYPE_PAUSE,
			WorktimeEventTable::EVENT_TYPE_STOP,
			WorktimeEventTable::EVENT_TYPE_EDIT_STOP,
			WorktimeEventTable::EVENT_TYPE_STOP_WITH_ANOTHER_TIME,
		], true);
	}

	private function resolveBreakStartTimestamp(WorktimeEvent $worktimeEvent): ?int
	{
		$recordedTimestamp = $worktimeEvent->getRecordedValue();
		$actualTimestamp = $worktimeEvent->getActualTimestamp();

		$breakStartTimestamp = $recordedTimestamp > 0 ? $recordedTimestamp : $actualTimestamp;

		return $breakStartTimestamp > 0 ? $breakStartTimestamp : null;
	}

	private function resolvePausedTimestamp(): ?int
	{
		$dateFinish = $this->getDateFinish();
		if ($dateFinish instanceof DateTime)
		{
			return $dateFinish->getTimestamp();
		}

		$actualStopTimestamp = (int)$this->getActualStopTimestamp();

		return $actualStopTimestamp > 0 ? $actualStopTimestamp : null;
	}

	private function hasFutureStopWhilePaused(int $recordStopUtcTimestamp, int $actualNowTimestamp): bool
	{
		return $this->isPaused() && $recordStopUtcTimestamp > $actualNowTimestamp;
	}

	private function shouldReduceBreaksOnContinue(int $continueUtcTimestamp): bool
	{
		if (!$this->wasPausedOnFutureStop($continueUtcTimestamp))
		{
			return false;
		}

		return $this->getRecordedBreakLength() >= $this->getActualBreakLength();
	}

	private function wasPausedOnFutureStop(int $continueUtcTimestamp): bool
	{
		$recordedStopTimestamp = (int)$this->getRecordedStopTimestamp();
		$actualStopTimestamp = (int)$this->getActualStopTimestamp();
		if (
			$recordedStopTimestamp <= 0
			|| $continueUtcTimestamp >= $recordedStopTimestamp
			|| $actualStopTimestamp <= 0
			|| $actualStopTimestamp >= $recordedStopTimestamp
		)
		{
			return false;
		}

		$wasPaused = false;
		$wasPausedOnStop = false;
		$stopFound = false;
		foreach ($this->getWorktimeEventsOrderedByActualTimestamp() as $worktimeEvent)
		{
			$eventActualTimestamp = (int)$worktimeEvent->getActualTimestamp();
			if ($eventActualTimestamp <= 0 || $eventActualTimestamp > $actualStopTimestamp)
			{
				continue;
			}

			$eventType = $worktimeEvent->getEventType();
			if ($eventType === WorktimeEventTable::EVENT_TYPE_PAUSE)
			{
				$wasPaused = true;

				continue;
			}

			if (
				$eventType === WorktimeEventTable::EVENT_TYPE_CONTINUE
				|| $eventType === WorktimeEventTable::EVENT_TYPE_START
				|| $eventType === WorktimeEventTable::EVENT_TYPE_START_WITH_ANOTHER_TIME
			)
			{
				$wasPaused = false;

				continue;
			}

			if (in_array($eventType, [
				WorktimeEventTable::EVENT_TYPE_STOP,
				WorktimeEventTable::EVENT_TYPE_EDIT_STOP,
				WorktimeEventTable::EVENT_TYPE_STOP_WITH_ANOTHER_TIME,
			], true))
			{
				$wasPausedOnStop = $wasPaused;
				$stopFound = true;
			}
		}

		return $stopFound && $wasPausedOnStop;
	}

	private function correctDuration(int $duration): int
	{
		$secondsPerDay = 86400;
		if ($duration < 0)
		{
			return $duration + $secondsPerDay;
		}
		if ($duration > $secondsPerDay)
		{
			return $secondsPerDay;
		}

		return $duration;
	}

	private function clampDaySeconds(int $seconds): int
	{
		$secondsPerDay = 86400;

		if ($seconds < 0)
		{
			return 0;
		}
		if ($seconds > $secondsPerDay)
		{
			return $secondsPerDay;
		}

		return $seconds;
	}

	private function clampNonNegative(int $seconds): int
	{
		return max($seconds, 0);
	}

	/**
	 * @return \Bitrix\Timeman\Model\User\User|null
	 */
	public function obtainUser()
	{
		try
		{
			return $this->getUser();
		}
		catch (\Exception $exc)
		{
		}
		return null;
	}

	public function collectRawValues(): array
	{
		return $this->collectValues(\Bitrix\Main\ORM\Objectify\Values::ALL, \Bitrix\Main\ORM\Fields\FieldTypeMask::FLAT);
	}
}

<?php

namespace Bitrix\TimemanMobile\Public\Providers;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Timeman\Helper\TimeHelper;
use Bitrix\Timeman\Service\DependencyManager;
use Bitrix\Timeman\V2\Public\Command\RecordIntent\RegisterOutcomeCommand;
use Bitrix\Timeman\V2\Public\Command\RecordIntent\RegisterShowCommand;
use Bitrix\Timeman\V2\Public\Dto\RecordIntent\RecordIntentSchedule;
use Bitrix\Timeman\V2\Public\Dto\Schedule\Schedule;
use Bitrix\Timeman\V2\Public\Dto\Shift\Shift;
use Bitrix\Timeman\V2\Public\Dto\Shift\ShiftCollection;
use Bitrix\Timeman\V2\Public\Provider\RecordIntentProvider;
use Bitrix\Timeman\V2\Public\Provider\RecordProvider;
use Bitrix\Timeman\V2\Public\Provider\SettingsProvider;
use Bitrix\TimemanMobile\Internal\Dto\ShiftDto;
use Bitrix\TimemanMobile\Internal\Dto\WorkdayIntentBoxDto;
use Bitrix\TimemanMobile\Internal\Dto\WorkdayIntentDto;
use Bitrix\TimemanMobile\Internal\Dto\WorkdayIntentScheduleDto;
use Bitrix\TimemanMobile\Internal\Integration\Bizproc\Providers\DayPlanBotProvider;
use Bitrix\TimemanMobile\Internal\Integration\Intranet\UserAbsence;
use Bitrix\TimemanMobile\Internal\Integration\Stafftrack\Provider\CheckInSettingsProvider;

class WorkdayIntentProvider
{
	private const INTENT_WORKDAY = 'WORKDAY';
	private const INTENT_WEEKEND = 'WEEKEND';
	private const ABSENCE_INTENT_IDS = ['LEAVEUNPAYED', 'VACATION', 'LEAVESICK'];

	private ?int $userId;
	private ServiceLocator $locator;
	private ?RecordIntentProvider $recordIntentProvider = null;
	private ?ShiftCollection $nearestShiftCollection = null;

	public function __construct()
	{
		$this->userId = CurrentUser::get()->getId();
		$this->locator = ServiceLocator::getInstance();
	}

	private function getRecordIntentProvider(): RecordIntentProvider
	{
		return $this->recordIntentProvider ??= new RecordIntentProvider();
	}

	/**
	 * @return bool
	 */
	public function canUseTimeman(): bool
	{
		return $this->locator->get(RecordProvider::class)->canUseTimeman($this->userId);
	}

	/**
	 * @return bool
	 */
	public function canManageWorktimeOnMobile(): bool
	{
		return $this->locator->get(RecordProvider::class)->canManageWorktimeOnMobile($this->userId);
	}

	/**
	 * @return bool
	 */
	public function canStartWorkday(): bool
	{
		$timemanUser = \CTimeManUser::instance();
		$workTimeState = $timemanUser->state();

		return $workTimeState === 'CLOSED' && $timemanUser->openAction() === 'OPEN';
	}

	/**
	 * @return bool
	 */
	public function canOpenWorkdayIntentBox(): bool
	{
		return
			$this->canUseTimeman()
			&& $this->canManageWorktimeOnMobile()
			&& $this->canStartWorkday()
		;
	}

	/**
	 * @return WorkdayIntentBoxDto
	 */
	public function getWorkdayIntentBoxData(): WorkdayIntentBoxDto
	{
		if (!$this->hasIntentBaseAdmission())
		{
			return WorkdayIntentBoxDto::make(['canOpen' => false]);
		}

		$recordIntentSchedule = $this->getRecordIntentProvider()->getSchedule($this->userId);
		$schedule = WorkdayIntentScheduleDto::make($recordIntentSchedule->toArray());

		$canOpen = $this->canStartWorkday() && $this->canShowNow($recordIntentSchedule);
		if (!$canOpen)
		{
			return WorkdayIntentBoxDto::make([
				'canOpen' => false,
				'schedule' => $schedule,
			]);
		}

		$intentList = $this->getIntents();

		return WorkdayIntentBoxDto::make([
			'canOpen' => true,
			'intentList' => $intentList,
			'shifts' => $this->getNearestShifts(),
			'preselectedIntentId' => $this->resolvePreselectedIntentId($intentList),
			'chatIdWithAiAdvice' => DayPlanBotProvider::getBotId(),
			'isDayStartCheckInEnabled' => (
				CheckInSettingsProvider::isCheckInEnabled()
				&& $this->locator->get(SettingsProvider::class)->isDayStartCheckInEnabled()
			),
			'schedule' => $schedule,
		]);
	}

	/**
	 * @param RecordIntentSchedule $schedule
	 * @return bool
	 */
	private function canShowNow(RecordIntentSchedule $schedule): bool
	{
		$now = time();

		return
			$now >= $schedule->targetStartTimestamp
			&& $now < $schedule->periodEndTimestamp
			&& $schedule->showCount < $schedule->maxShows
			&& $schedule->dismissCount < $schedule->maxDismisses
			&& $now >= $schedule->suppressedUntilTimestamp
			&& $schedule->hasTargetAction === false
			// Server-side retry interval, unified across devices (AC-6): the next show is not earlier than
			// retryIntervalSeconds after the previous one. lastShownTimestamp = 0 does not block the first show.
			&& $now >= $schedule->lastShownTimestamp + $schedule->retryIntervalSeconds
			&& $schedule->shiftStartable
		;
	}

	/**
	 * Base mobile admission for the intent scenario. Mirrors the gate in getWorkdayIntentBoxData:
	 * only users who may participate are allowed to touch the timeman intent-state (which lazily
	 * stamps firstUseAt on first resolve). Mobile eligibility is the consumer layer's responsibility.
	 *
	 * @return bool
	 */
	private function hasIntentBaseAdmission(): bool
	{
		// Guard against an empty current user before reaching the int-typed timeman signatures
		// (RecordProvider::canUseTimeman/canManageWorktimeOnMobile, RecordIntentProvider::getSchedule).
		if (empty($this->userId))
		{
			return false;
		}

		return $this->canUseTimeman() && $this->canManageWorktimeOnMobile();
	}

	/**
	 * @param string $periodKey
	 * @return Result
	 */
	public function registerShow(string $periodKey): Result
	{
		if (!$this->hasIntentBaseAdmission())
		{
			return (new Result())->addError(new Error('Access denied', 'access_denied'));
		}

		return (new RegisterShowCommand($this->userId, $periodKey))->run();
	}

	/**
	 * @param string $periodKey
	 * @param string $outcome
	 * @return Result
	 */
	public function registerOutcome(string $periodKey, string $outcome): Result
	{
		if (!$this->hasIntentBaseAdmission())
		{
			return (new Result())->addError(new Error('Access denied', 'access_denied'));
		}

		return (new RegisterOutcomeCommand($this->userId, $periodKey, $outcome))->run();
	}

	/**
	 * @return array
	 */
	public function getIntents(): array
	{
		$baseIntents = [self::INTENT_WORKDAY, self::INTENT_WEEKEND];
		$preferredAbsences = self::ABSENCE_INTENT_IDS;
		$availableAbsences = UserAbsence::getAbsenceTypes();
		$absenceKeys = array_values(array_intersect($preferredAbsences, $availableAbsences));

		return array_map(
			fn (string $id): WorkdayIntentDto => WorkdayIntentDto::make([
				'id' => $id,
				'label' => Loc::getMessage("TIMEMANMOBILE_WORKDAY_INTENT_PROVIDER_INTENT_{$id}"),
			]),
			array_merge($baseIntents, $absenceKeys),
		);
	}

	/**
	 * @return ShiftDto[]
	 */
	public function getNearestShifts(): array
	{
		$shiftCollection = $this->getNearestShiftCollection();
		if ($shiftCollection->isEmpty())
		{
			return [$this->buildFlextimeShift()];
		}

		/** @var ShiftDto[] $shifts */
		$shifts = $shiftCollection->map(
			function (Shift $shift): ShiftDto {
				$shiftArray = $shift->toArray();

				return ShiftDto::make([
					...$shiftArray,
					'scheduleType' => $shiftArray['schedule']['type'],
					'startTimestamp' => DateTime::createFromUserTime($shiftArray['start'])->getTimestamp(),
					'stopTimestamp' => DateTime::createFromUserTime($shiftArray['stop'])->getTimestamp(),
				]);
			},
		);
		/** @var Schedule|null $schedule */
		$schedule = $shiftCollection->getIterator()->current()?->schedule;

		// No schedule on the first shift: fall back to a synthetic flextime shift, same as the
		// non-shifted / non-fixed (free schedule) branches below.
		if ($schedule === null)
		{
			return [$this->buildFlextimeShift()];
		}

		if ($schedule->isShifted())
		{
			return $this->filterActualShifts($shifts) ?: [$this->buildFlextimeShift()];
		}

		if ($schedule->isFixed())
		{
			if (!empty($actualShifts = $this->filterActualShifts($shifts)))
			{
				return [$actualShifts[0]];
			}

			return [$this->buildFlextimeShift()];
		}

		return [$this->buildFlextimeShift()];
	}

	/**
	 * @return ShiftCollection
	 */
	private function getNearestShiftCollection(): ShiftCollection
	{
		return $this->nearestShiftCollection ??=
			$this->locator->get(RecordProvider::class)->getNearestShifts($this->userId)
		;
	}

	/**
	 * @param WorkdayIntentDto[] $intentList
	 * @return string
	 */
	private function resolvePreselectedIntentId(array $intentList): string
	{
		try
		{
			$intentIds = array_map(
				static fn (WorkdayIntentDto $intent): string => $intent->id,
				$intentList,
			);

			return $this->findAbsenceIntentId($intentIds) ?? $this->resolveScheduleIntentId();
		}
		catch (\Throwable)
		{
			return self::INTENT_WORKDAY;
		}
	}

	/**
	 * @param string[] $intentIds
	 * @return string|null
	 */
	private function findAbsenceIntentId(array $intentIds): ?string
	{
		$absenceIntentIds = array_intersect(self::ABSENCE_INTENT_IDS, $intentIds);
		if (empty($absenceIntentIds))
		{
			return null;
		}

		$userNow = TimeHelper::getInstance()->getUserDateTimeNow($this->userId);
		$day = new Date($userNow->format('Y-m-d'), 'Y-m-d');

		foreach (UserAbsence::getAbsenceTypesByDay($this->userId, $day) as $absenceType)
		{
			if (in_array($absenceType, $absenceIntentIds, true))
			{
				return $absenceType;
			}
		}

		return null;
	}

	/**
	 * @return string
	 */
	private function resolveScheduleIntentId(): string
	{
		$shiftCollection = $this->getNearestShiftCollection();

		if ($shiftCollection->isEmpty())
		{
			// no schedules assigned means "can work any day"; a fixed/shifted
			// schedule without shifts in the nearest window means a day off
			$schedules = DependencyManager::getInstance()
				->getScheduleProvider()
				->findSchedulesByUserId($this->userId)
			;

			return empty($schedules) ? self::INTENT_WORKDAY : self::INTENT_WEEKEND;
		}

		/** @var ?Schedule $schedule */
		$schedule = $shiftCollection->getIterator()->current()?->schedule;
		if ($schedule === null || (!$schedule->isFixed() && !$schedule->isShifted()))
		{
			return self::INTENT_WORKDAY;
		}

		return $this->hasShiftStartingOnCurrentUserDay($shiftCollection)
			? self::INTENT_WORKDAY
			: self::INTENT_WEEKEND
		;
	}

	/**
	 * Checks the calendar day in the user timezone, unlike filterActualShifts()
	 * which uses a sliding now..now+24h window. Shift->start holds the schedule
	 * wall time encoded in UTC, so only its calendar date is comparable with
	 * the user's local date; timestamps of these two values are not.
	 *
	 * @param ShiftCollection $shiftCollection
	 * @return bool
	 */
	private function hasShiftStartingOnCurrentUserDay(ShiftCollection $shiftCollection): bool
	{
		$userDay = TimeHelper::getInstance()->getUserDateTimeNow($this->userId)->format('Y-m-d');

		$shiftOnDay = $shiftCollection->find(
			static fn (Shift $shift): bool =>
				$shift->start !== null
				&& $shift->start->format('Y-m-d') === $userDay,
		);

		return $shiftOnDay !== null;
	}

	/**
	 * @return ShiftDto
	 */
	private function buildFlextimeShift(): ShiftDto
	{
		$timeZone = TimeHelper::getInstance()->getUserDateTimeZone((int)$this->userId);
		$dayStart = (new \DateTime('now', $timeZone))->setTime(0, 0);
		$dayEnd = (clone $dayStart)->add(new \DateInterval('P1D'));

		return ShiftDto::make([
			'start' => $dayStart->format(DateTime::getFormat()),
			'stop' => $dayEnd->format(DateTime::getFormat()),
			'startTimestamp' => $dayStart->getTimestamp(),
			'stopTimestamp' => $dayEnd->getTimestamp(),
			'scheduleType' => 'flextime',
		]);
	}

	/**
	 * @param ShiftDto[] $shifts
	 * @return ShiftDto[]
	 */
	private function filterActualShifts(array $shifts): array
	{
		$now = time();
		$startIndex = null;

		foreach ($shifts as $index => $shift)
		{
			if ($shift->startTimestamp <= $now && $now < $shift->stopTimestamp)
			{
				$startIndex = $index;
				break;
			}

			if ($shift->startTimestamp > $now)
			{
				$startIndex = $index;
				break;
			}
		}

		if ($startIndex === null)
		{
			return [];
		}

		$result = [];
		$shiftsCount = count($shifts);

		for ($i = $startIndex; $i < $shiftsCount; $i++)
		{
			if ($shifts[$i]->startTimestamp >= $now + 86400)
			{
				break;
			}

			$result[] = $shifts[$i];
		}

		return $result;
	}

	/**
	 * Records an absence on the target startable shift from the timeman schedule (RecordIntentSchedule):
	 * the very shift "Start" would open. The shifts coming from the frontend (getNearestShifts) are no
	 * longer used for interval selection — after a shift ends they point to the next shift and diverge
	 * from the real start. When there is no target shift (true day off of a fixed schedule, or a free
	 * schedule with no shift) the absence is not written: "Day off" behaves like the existing WEEKEND
	 * UI choice — closes the popup without a record.
	 *
	 * @param string $absenceType
	 * @param array $shifts kept for transport compatibility; not used for interval selection
	 * @return Result
	 */
	public function addAbsence(string $absenceType, array $shifts): Result
	{
		if (!$this->hasIntentBaseAdmission())
		{
			return (new Result())->addError(new Error('Access denied', 'access_denied'));
		}

		$schedule = $this->getRecordIntentProvider()->getSchedule($this->userId);

		$result = new Result();

		// No target shift: no-op with a successful response (acts like the UI weekend choice).
		if ($schedule->targetShiftStartTimestamp <= 0 || $schedule->targetShiftStopTimestamp <= 0)
		{
			return $result->setData(['absences' => []]);
		}

		$absences = [
			[
				'from' => DateTime::createFromTimestamp($schedule->targetShiftStartTimestamp),
				'to' => DateTime::createFromTimestamp($schedule->targetShiftStopTimestamp),
				'description' => Loc::getMessage("TIMEMANMOBILE_WORKDAY_INTENT_PROVIDER_INTENT_{$absenceType}")
					?: Loc::getMessage('TIMEMANMOBILE_WORKDAY_INTENT_PROVIDER_ABSENCE_DESCRIPTION'),
				'type' => $absenceType,
			],
		];

		$resultAbsences = [];

		$addResults = UserAbsence::addAbsences($this->userId, $absences);
		foreach ($addResults as $addResult)
		{
			if (!$addResult->isSuccess())
			{
				$result->addErrors($addResult->getErrors());

				continue;
			}

			$resultAbsences[] = $addResult->getData()['absence'] ?? null;
		}

		return $result->setData(['absences' => array_filter($resultAbsences)]);
	}
}

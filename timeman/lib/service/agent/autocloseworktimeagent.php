<?php
namespace Bitrix\Timeman\Service\Agent;

use Bitrix\Main\Localization\Loc;
use Bitrix\Timeman\Form\Worktime\WorktimeRecordForm;
use Bitrix\Timeman\Helper\TimeHelper;
use Bitrix\Timeman\Repository\Worktime\WorktimeRepository;
use Bitrix\Timeman\Service\DependencyManager;
use Bitrix\Timeman\Service\Worktime\WorktimeService;

Loc::loadMessages(__FILE__);

class AutoCloseWorktimeAgent
{
	/** @var WorktimeRepository */
	private $worktimeRepository;
	private $worktimeService;

	public function __construct(WorktimeRepository $worktimeRepository, WorktimeService $worktimeService)
	{
		$this->worktimeRepository = $worktimeRepository;
		$this->worktimeService = $worktimeService;
	}

	public static function runCloseRecord($recordId)
	{
		return DependencyManager::getInstance()
			->getAutoCloseWorktimeAgent()
			->closeRecord($recordId);
	}

	public function closeRecord($recordId)
	{
		$record = $this->worktimeRepository->findByIdWith($recordId, ['SCHEDULE', 'SHIFT']);
		if (!$record || $record->getRecordedStopTimestamp() > 0 ||
			!$record->obtainSchedule() || !$record->obtainSchedule()->isAutoClosing())
		{
			return '';
		}
		$manager = DependencyManager::getInstance()
			->buildWorktimeRecordManager(
				$record,
				$record->obtainSchedule(),
				$record->obtainShift()
			);
		$recordStopUtcTimestamp = $manager->buildStopTimestampForAutoClose();
		if ($recordStopUtcTimestamp === null)
		{
			return '';
		}

		if ($recordStopUtcTimestamp <= $record->getActualStartTimestamp())
		{
			return '';
		}

		// The exact stop instant is carried through recordedStopTimestamp on the trusted system path, so
		// StopCustomTimeWorktimeManager uses it verbatim WITHOUT a wall-time round-trip. The wall
		// seconds/date below are derived from the same instant for downstream display consumers, but they
		// must NOT redefine the stop moment: on a fall-back the local time is ambiguous and rebuilding from
		// wall-time could pick the other occurrence and shift the stop by an hour.
		$recordStop = (new \DateTime('@' . (int)$recordStopUtcTimestamp))
			->setTimezone(TimeHelper::getInstance()->getUserDateTimeZone($record->getUserId()));
		$recordForm = WorktimeRecordForm::createWithEventForm();
		$recordForm->recordedStopTimestamp = $recordStopUtcTimestamp;
		$recordForm->recordedStopSeconds = TimeHelper::getInstance()->getSecondsFromDateTime($recordStop);
		$recordForm->recordedStopDateFormatted = \Bitrix\Main\Type\Date::createFromPhp($recordStop)->toString();
		$recordForm->userId = $record->getUserId();
		$recordForm->isSystem = true;
		// STOP_OFFSET is no longer forced to START_OFFSET: stopWork() derives it date-aware (ALG-02) at the
		// stop instant from the employee's real IANA zone. Day continuity holds because both ends live in
		// the same IANA zone (resolveEffectiveTimeZoneId); on a DST boundary inside the day STOP_OFFSET
		// legitimately differs from START_OFFSET. This relies on DURATION being elapsed-based (ALG-03).

		if (\Bitrix\Timeman\Integration\Stafftrack\CheckIn::isCheckInStartEnabled())
		{
			$recordForm->getFirstEventForm()->reason = Loc::getMessage('TIMEMAN_CHECK_IN_CLOSE_DAY_REASON');
		}

		$result = $this->worktimeService->stopWorktime($recordForm);
		if (
			\Bitrix\Timeman\Integration\Stafftrack\CheckIn::isCheckInStartEnabled()
			&& $result->isSuccess()
		)
		{
			$reportData = [];
			$queryObject = \CTimeManReport::getList(
				[],
				[
					'ENTRY_ID' => $record->getId(),
					'REPORT_TYPE' => 'REPORT',
				],
			);
			if ($report = $queryObject->fetch())
			{
				$reportData['REPORT'] = $report['REPORT'];
			}

			\CTimeManReportDaily::Add([
				'USER_ID' => $record->getUserId(),
				'ENTRY_ID' => $record->getId(),
				'REPORT_DATE' => $record->getDateStart()->setTime(0, 0),
				'ACTIVE' => $record->getActive() ? 'Y' : 'N',
				'REPORT' => $reportData['REPORT'] ?? '',
			], true);
		}

		return '';
	}

}
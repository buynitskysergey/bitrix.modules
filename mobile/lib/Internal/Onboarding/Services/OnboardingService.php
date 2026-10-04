<?php

namespace Bitrix\Mobile\Internal\Onboarding\Services;

use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Onboarding\Checkers\LastPushSentChecker;
use Bitrix\Mobile\Internal\Onboarding\Checkers\PortalAgeChecker;
use Bitrix\Mobile\Internal\Onboarding\Conditions\PortalAgeCondition;
use Bitrix\Mobile\Internal\Onboarding\Conditions\PushSendConditionChain;
use Bitrix\Mobile\Internal\Onboarding\Providers\ActionTypeProvider;
use Bitrix\Mobile\Internal\Onboarding\Providers\PushContentProvider;
use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionDetector;
use Bitrix\Mobile\Internal\Onboarding\Result\ProcessResult;
use Bitrix\Mobile\Internal\Onboarding\Result\PushSendResult;
use Bitrix\Mobile\Internal\Onboarding\Schedule\ScheduleInterface;
use Bitrix\Mobile\Internal\Onboarding\Schedule\WorkdaySchedule;
use Bitrix\Mobile\Push\Message;
use Bitrix\Mobile\Push\Sender;

class OnboardingService
{
	private UserSelectorService $userSelector;
	private MobileActivityService $activityService;
	private RegionDetector $regionDetector;
	private PortalAgeChecker $portalAgeChecker;
	private OnboardingPushService $pushStorage;
	private LastPushSentChecker $lastPushChecker;

	public function __construct(
		?UserSelectorService $userSelector = null,
		?MobileActivityService $activityService = null,
		?RegionDetector $regionDetector = null,
		?PortalAgeChecker $portalAgeChecker = null,
		?OnboardingPushService $pushStorage = null,
		?LastPushSentChecker $lastPushChecker = null,
	)
	{
		$this->activityService = $activityService ?? new MobileActivityService();
		$this->userSelector = $userSelector ?? new UserSelectorService($this->activityService);
		$this->portalAgeChecker = $portalAgeChecker ?? new PortalAgeChecker();
		$this->regionDetector = $regionDetector ?? new RegionDetector($this->portalAgeChecker);
		$this->pushStorage = $pushStorage ?? new OnboardingPushService();
		$this->lastPushChecker = $lastPushChecker ?? new LastPushSentChecker($this->pushStorage);
	}

	public function processAllUsers(): ProcessResult
	{
		$result = new ProcessResult();
		$regionConfig = $this->regionDetector->detect();
		$validation = $regionConfig->validate();
		if (!$validation->isSuccess())
		{
			$this->logPortalDateIssue($validation);
			$result->addErrors($validation->getErrors());

			return $result;
		}

		$activityTimestamps = $this->activityService->getActivityTimestampsByUser();
		$userIds = $this->userSelector->filterValidUserIds(array_keys($activityTimestamps));
		$sent = 0;

		foreach ($userIds as $userId)
		{
			try
			{
				$activityDate = DateTime::createFromTimestamp($activityTimestamps[$userId]);
				if ($this->sendScheduledPushForRegion($userId, $regionConfig, $activityDate)->wasSent())
				{
					$sent++;
				}
			}
			catch (\Throwable $e)
			{
				AddMessage2Log('Onboarding push: failed for user ' . $userId . ' — ' . $e->getMessage(), 'mobile');
			}
		}

		return $result
			->setProcessed(count($userIds))
			->setSent($sent)
			->setSkipped(count($userIds) - $sent)
		;
	}

	public function getScheduledDayForToday(DateTime $activityDate, RegionConfig $regionConfig): ?int
	{
		return $this->getScheduledDayForDate($activityDate, $regionConfig, (new DateTime())->format('Y-m-d'));
	}

	public function getScheduledDayForDate(DateTime $activityDate, RegionConfig $regionConfig, string $targetDate): ?int
	{
		$schedule = $this->getScheduleForRegion();

		foreach ($regionConfig->getScheduleDays() as $day)
		{
			$scheduledDate = $schedule
				->calculateSendDate($activityDate, $day)
				->format('Y-m-d')
			;

			if ($scheduledDate === $targetDate)
			{
				return $day;
			}
		}

		return null;
	}

	public function getUserSchedule(int $userId): array
	{
		$activityDate = $this->activityService->getActivityDate($userId);
		if ($activityDate === null)
		{
			return [];
		}

		$regionConfig = $this->regionDetector->detect();
		$schedule = $this->getScheduleForRegion();
		$result = [];

		foreach ($regionConfig->getScheduleMap() as $day => $pushType)
		{
			$scheduledDate = $schedule->calculateSendDate($activityDate, $day);
			$result[] = [
				'day' => $day,
				'date' => $scheduledDate->format('Y-m-d'),
				'weekday' => $scheduledDate->format('l'),
				'pushType' => $pushType?->value,
			];
		}

		return $result;
	}

	public function getUserInfo(int $userId): array
	{
		$activityDate = $this->activityService->getActivityDate($userId);
		$daysSince = $this->activityService->getDaysSinceActivity($userId);
		$regionConfig = $this->regionDetector->detect();

		return [
			'hasMobileActivity' => $activityDate !== null,
			'activityDate' => $activityDate?->format('Y-m-d H:i:s'),
			'daysSinceActivity' => $daysSince,
			'region' => $regionConfig->getCode(),
			'isValidUser' => $this->userSelector->isValidUser($userId),
			'portalEligible' => $this->portalAgeChecker->isEligible($regionConfig->getMaxPortalAgeDays()),
			'portalZoneSupported' => $regionConfig->isSupported(),
		];
	}

	public function sendScheduledPush(int $userId): PushSendResult
	{
		$regionConfig = $this->regionDetector->detect();
		$validation = $regionConfig->validate();
		if (!$validation->isSuccess())
		{
			$this->logPortalDateIssue($validation);
			$result = new PushSendResult();
			$result->addErrors($validation->getErrors());

			return $result;
		}

		return $this->sendScheduledPushForRegion($userId, $regionConfig);
	}

	public function sendForcePush(int $userId): PushSendResult
	{
		$regionConfig = $this->regionDetector->detect();
		$validation = PushSendConditionChain::createForceChain($this->portalAgeChecker)->check($regionConfig);
		if (!$validation->isSuccess())
		{
			$this->logPortalDateIssue($validation);
			$result = new PushSendResult();
			$result->addErrors($validation->getErrors());

			return $result;
		}

		return $this->sendScheduledPushForRegion($userId, $regionConfig, ignoreAlreadySent: true);
	}

	public function getLastPushInfo(int $userId): ?array
	{
		$lastPush = $this->pushStorage->getLastSentPush($userId);

		if ($lastPush === null)
		{
			return null;
		}

		return [
			'type' => $lastPush->type,
			'date' => DateTime::createFromTimestamp($lastPush->timestamp)->format('Y-m-d H:i:s'),
		];
	}

	private function getScheduleForRegion(): ScheduleInterface
	{
		return new WorkdaySchedule();
	}

	private function logPortalDateIssue(Result $validation): void
	{
		foreach ($validation->getErrors() as $error)
		{
			if ($error->getMessage() === PortalAgeCondition::ERROR_DATE_UNDEFINED)
			{
				AddMessage2Log(
					'Onboarding push: portal creation date is undefined'
					. ' (option main/~controller_date_create is empty) — pushes are blocked for the entire portal',
					'mobile',
				);

				return;
			}
		}
	}

	private function sendScheduledPushForRegion(
		int $userId,
		RegionConfig $regionConfig,
		?DateTime $activityDate = null,
		bool $ignoreAlreadySent = false,
	): PushSendResult
	{
		$result = new PushSendResult();

		$activityDate ??= $this->activityService->getActivityDate($userId);
		if ($activityDate === null)
		{
			$result->addError(new Error('No mobile activity'));

			return $result;
		}

		$scheduledDay = $this->getScheduledDayForToday($activityDate, $regionConfig);
		if ($scheduledDay === null)
		{
			$result->addError(new Error('No push scheduled for today'));

			return $result;
		}

		$pushType = $regionConfig->getPushTypeForDay($scheduledDay);
		if ($pushType === null)
		{
			$result->addError(new Error('Push type not configured for day'));

			return $result;
		}

		$result->setPushType($pushType->value);

		$scheduledDate = $this->getScheduleForRegion()->calculateSendDate($activityDate, $scheduledDay);
		if (!$ignoreAlreadySent && $this->lastPushChecker->isAlreadySent($userId, $pushType, $scheduledDate))
		{
			$result->addError(new Error('Already sent'));

			return $result;
		}

		$sendResult = $this->sendPush($userId, $scheduledDay, $pushType, $regionConfig);

		if ($sendResult->isSuccess())
		{
			return $result;
		}

		$result->addErrors($sendResult->getErrors());

		return $result;
	}

	private function sendPush(int $userId, int $day, PushType $pushType, RegionConfig $regionConfig): Result
	{
		if (!Loader::includeModule('pull'))
		{
			return (new Result())->addError(new Error('Pull module not available'));
		}

		$contentProvider = new PushContentProvider($regionConfig);
		$actionTypeProvider = new ActionTypeProvider($userId);

		$title = $contentProvider->getTitleForDay($day);
		$body = $contentProvider->getBodyForDay($day);
		if ($title === '' || $body === '')
		{
			return (new Result())->addError(new Error('Empty push content for day ' . $day));
		}

		$message = new Message(
			$actionTypeProvider->getActionType($pushType),
			$title,
			$body,
		);

		$result = Sender::sendImmediate($userId, $message);

		if ($result->isSuccess() && $userId > 0)
		{
			$this->pushStorage->setLastSentPush($userId, $pushType->value);
			$this->sendAnalytics($pushType);
		}

		return $result;
	}

	private function sendAnalytics(PushType $type): void
	{
		$analyticsType = '1-8d';
		$event = new AnalyticsEvent($type->getAnalyticsEvent(), 'mobile', $analyticsType);
		$event->send();
	}
}

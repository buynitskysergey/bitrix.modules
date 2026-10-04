<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers;

use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Onboarding\Services\OnboardingPushService;
use Bitrix\Mobile\Internal\Onboarding\PushType;

class LastPushSentChecker
{
	private OnboardingPushService $pushStorage;

	public function __construct(?OnboardingPushService $pushStorage = null)
	{
		$this->pushStorage = $pushStorage ?? new OnboardingPushService();
	}

	public function isAlreadySent(int $userId, PushType $pushType, DateTime $scheduledDate): bool
	{
		$lastPush = $this->pushStorage->getLastSentPush($userId);
		if ($lastPush === null)
		{
			return false;
		}

		$isSameType = $lastPush->type === $pushType->value;

		$sentDay = DateTime::createFromTimestamp($lastPush->timestamp)->setTime(0, 0, 0);
		$scheduledDay = (clone $scheduledDate)->setTime(0, 0, 0);
		$isSentForScheduledDate = $sentDay->getTimestamp() >= $scheduledDay->getTimestamp();

		return $isSameType && $isSentForScheduledDate;
	}
}

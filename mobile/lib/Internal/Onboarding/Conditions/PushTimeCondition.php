<?php

namespace Bitrix\Mobile\Internal\Onboarding\Conditions;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

class PushTimeCondition implements PushSendCondition
{
	private const SEND_WINDOW_HOURS = 2;

	public function __construct(private readonly ?int $currentTimestamp = null)
	{}

	public function check(RegionConfig $config): Result
	{
		$result = new Result();

		if (!$this->isWithinSendWindow($config))
		{
			$result->addError(new Error('Push sending time not reached or window expired'));
		}

		return $result;
	}

	private function isWithinSendWindow(RegionConfig $config): bool
	{
		$timeSend = $config->getTimeSend();
		if (!preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$/', $timeSend))
		{
			return false;
		}

		$parsedTime = \DateTime::createFromFormat('H:i', $timeSend);
		if ($parsedTime === false)
		{
			return false;
		}

		$sendTime = $parsedTime->format(DateTime::getFormat());
		$sendDateTime = DateTime::createFromUserTime($sendTime);
		$currentTimestamp = $this->currentTimestamp ?? (new DateTime())->getTimestamp();

		$windowStart = $sendDateTime->getTimestamp();
		$windowEnd = $windowStart + self::SEND_WINDOW_HOURS * 3600;

		return $currentTimestamp >= $windowStart && $currentTimestamp < $windowEnd;
	}
}

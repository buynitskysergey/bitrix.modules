<?php

namespace Bitrix\Mobile\Internal\Onboarding\Schedule;

use Bitrix\Main\Type\DateTime;

class WorkdaySchedule implements ScheduleInterface
{
	private const SATURDAY = 6;
	private const SUNDAY = 0;

	public function calculateSendDate(DateTime $firstLoginDate, int $offsetDays): DateTime
	{
		$date = clone $firstLoginDate;
		$remaining = $offsetDays;
		$guard = $offsetDays * 2 + 14;

		while ($remaining > 0 && $guard-- > 0)
		{
			$date->add('1 day');
			if ($this->isValidSendDay($date))
			{
				$remaining--;
			}
		}

		return $date;
	}

	public function isValidSendDay(DateTime $date): bool
	{
		$dayOfWeek = (int)$date->format('w');

		return $dayOfWeek !== self::SATURDAY && $dayOfWeek !== self::SUNDAY;
	}
}

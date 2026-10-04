<?php

namespace Bitrix\Mobile\Internal\Onboarding\Schedule;

use Bitrix\Main\Type\DateTime;

interface ScheduleInterface
{
	public function calculateSendDate(DateTime $firstLoginDate, int $offsetDays): DateTime;

	public function isValidSendDay(DateTime $date): bool;
}

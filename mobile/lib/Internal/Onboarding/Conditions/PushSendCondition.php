<?php

namespace Bitrix\Mobile\Internal\Onboarding\Conditions;

use Bitrix\Main\Result;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

interface PushSendCondition
{
	public function check(RegionConfig $config): Result;
}

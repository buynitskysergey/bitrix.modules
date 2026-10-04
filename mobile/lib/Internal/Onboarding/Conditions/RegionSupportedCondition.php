<?php

namespace Bitrix\Mobile\Internal\Onboarding\Conditions;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

class RegionSupportedCondition implements PushSendCondition
{
	public function check(RegionConfig $config): Result
	{
		$result = new Result();

		if (!$config->isSupported())
		{
			$result->addError(new Error('Onboarding push feature is disabled'));
		}

		return $result;
	}
}

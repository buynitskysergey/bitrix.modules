<?php

namespace Bitrix\Mobile\Internal\Onboarding\Conditions;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Mobile\Internal\Onboarding\Checkers\PortalAgeChecker;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

readonly class PortalAgeCondition implements PushSendCondition
{
	public const ERROR_DATE_UNDEFINED = 'Portal creation date is undefined';
	public const ERROR_TOO_OLD = 'Portal too old';

	public function __construct(private PortalAgeChecker $portalAgeChecker)
	{}

	public function check(RegionConfig $config): Result
	{
		$result = new Result();

		if (!$this->portalAgeChecker->isPortalDateKnown())
		{
			$result->addError(new Error(self::ERROR_DATE_UNDEFINED));

			return $result;
		}

		if (!$this->portalAgeChecker->isEligible($config->getMaxPortalAgeDays()))
		{
			$result->addError(new Error(self::ERROR_TOO_OLD));
		}

		return $result;
	}
}

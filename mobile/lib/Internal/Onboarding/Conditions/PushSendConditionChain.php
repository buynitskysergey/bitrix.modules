<?php

namespace Bitrix\Mobile\Internal\Onboarding\Conditions;

use Bitrix\Main\Result;
use Bitrix\Mobile\Internal\Onboarding\Checkers\PortalAgeChecker;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

readonly class PushSendConditionChain
{
	/**
	 * @param array<PushSendCondition> $conditions
	 */
	public function __construct(private array $conditions)
	{}

	public static function createChain(?PortalAgeChecker $portalAgeChecker = null): self
	{
		$conditions = [
			new RegionSupportedCondition(),
		];

		if ($portalAgeChecker !== null)
		{
			$conditions[] = new PortalAgeCondition($portalAgeChecker);
		}

		$conditions[] = new PushTimeCondition();

		return new self($conditions);
	}

	public static function createForceChain(?PortalAgeChecker $portalAgeChecker = null): self
	{
		$conditions = [
			new RegionSupportedCondition(),
		];

		if ($portalAgeChecker !== null)
		{
			$conditions[] = new PortalAgeCondition($portalAgeChecker);
		}

		return new self($conditions);
	}

	public function check(RegionConfig $config): Result
	{
		foreach ($this->conditions as $condition)
		{
			$result = $condition->check($config);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		return new Result();
	}
}

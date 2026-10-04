<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\Crm\MultiValueStoreService;

trait JobCallAssessmentBindingTrait
{
	public static function generateJobCallAssessmentBindKey(int $jobId, int $activityId): string
	{
		return "job_{$jobId}_activity_{$activityId}_bind_call_assessment";
	}

	private static function cleanupJobCallAssessmentBinding(?int $parentJobId, ?int $activityId): void
	{
		if ($parentJobId === null || $activityId === null)
		{
			return;
		}

		MultiValueStoreService::getInstance()->deleteAll(
			self::generateJobCallAssessmentBindKey($parentJobId, $activityId),
		);
	}
}

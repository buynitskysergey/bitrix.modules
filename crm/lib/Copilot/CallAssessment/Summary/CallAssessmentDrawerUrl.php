<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary;

final class CallAssessmentDrawerUrl
{
	public const SCENARIO = 'call-assessment';
	public const PATH = '/crm/ai-report-drawer/call-assessment/';

	public static function build(
		int $activityId,
		int $ownerTypeId,
		int $ownerId,
		int $jobId = 0,
	): string
	{
		if ($activityId <= 0 || $ownerTypeId <= 0 || $ownerId <= 0)
		{
			return '';
		}

		$params = [
			'activityId' => $activityId,
			'ownerTypeId' => $ownerTypeId,
			'ownerId' => $ownerId,
		];
		if ($jobId > 0)
		{
			$params['jobId'] = $jobId;
		}

		return self::PATH . '?' . http_build_query($params);
	}
}

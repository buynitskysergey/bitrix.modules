<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary\Detector;

use Bitrix\Crm\Copilot\AiQualityAssessment\Controller\AiQualityAssessmentController;
use Bitrix\Crm\Copilot\AiQualityAssessment\Entity\AiQualityAssessmentTable;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Situation;
use Bitrix\Main\Type\DateTime;

final class RatingDetector
{
	public function getTopAssessmentIds(
		int $managerId,
		Situation $situation,
		?DateTime $since = null,
		int $limit = 3,
	): array
	{
		if (!in_array($situation, [Situation::RatingRaised, Situation::RatingDropped], true))
		{
			return [];
		}

		$assessmentDir = $situation === Situation::RatingRaised ? 'DESC' : 'ASC';

		return AiQualityAssessmentController::getInstance()->getAssessmentIdsForManager(
			$managerId,
			['ASSESSMENT' => $assessmentDir, 'ID' => 'DESC'],
			$limit,
			$since,
		);
	}

	public function getPreviousRating(int $managerId): ?int
	{
		if ($managerId <= 0)
		{
			return null;
		}

		$row = AiQualityAssessmentTable::query()
			->setSelect(['ASSESSMENT_AVG'])
			->where('RATED_USER_ID', $managerId)
			->where('USE_IN_RATING', true)
			->where('ACTIVITY_TYPE', AiQualityAssessmentTable::ACTIVITY_TYPE_CALL)
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->setOffset(1)
			->fetch()
		;

		if (is_array($row))
		{
			return (int)($row['ASSESSMENT_AVG'] ?? 0);
		}

		return null;
	}

	public function detect(
		Situation $situation,
		int $threshold,
		int $currentRating,
		?int $previousRating,
	): bool
	{
		if ($previousRating === null)
		{
			return false;
		}

		return match ($situation)
		{
			Situation::RatingRaised => $currentRating > $previousRating && $currentRating >= $threshold,
			Situation::RatingDropped => $currentRating < $previousRating && $currentRating <= $threshold,
			default => false,
		};
	}
}

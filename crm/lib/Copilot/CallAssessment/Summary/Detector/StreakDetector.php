<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary\Detector;

use Bitrix\Crm\Copilot\AiQualityAssessment\Controller\AiQualityAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessmentTable;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Situation;

final class StreakDetector
{
	private static array $scenarioBordersCache = [];

	/**
	 * @return array{
	 *     managerId: int,
	 *     assessmentIds: list<int>,
	 *     avgAssessment: int,
	 *     thresholdValue: int,
	 * }|null
	 */
	public function detectStreakLength(int $managerId, Situation $situation, int $requiredLength): ?array
	{
		$availableSituations = [Situation::GoodStreak, Situation::BadStreak];
		if (!in_array($situation, $availableSituations, true))
		{
			return null;
		}

		if ($managerId <= 0 || $requiredLength <= 0)
		{
			return null;
		}

		$rows = AiQualityAssessmentController::getInstance()
			->getRecentForManager($managerId, $requiredLength)
		;

		if (count($rows) < $requiredLength)
		{
			return null;
		}

		$settingIds = array_values(array_unique(array_map(
			static fn(array $row): int => (int)($row['ASSESSMENT_SETTING_ID'] ?? 0),
			$rows,
		)));
		$borders = $this->loadScenarioBorders($settingIds);

		$assessmentIds = [];
		$assessments = [];
		$bordersSeen = [];

		$isGoodStreak = $situation === Situation::GoodStreak;

		foreach ($rows as $row)
		{
			$assessment = $row['ASSESSMENT'];
			$settingId = $row['ASSESSMENT_SETTING_ID'] ?? 0;
			$lowBorder = $borders[$settingId]['low'] ?? CallAssessmentItem::LOW_BORDER_DEFAULT;
			$highBorder = $borders[$settingId]['high'] ?? CallAssessmentItem::HIGH_BORDER_DEFAULT;

			$passes = match ($situation)
			{
				Situation::GoodStreak => $assessment >= $highBorder,
				Situation::BadStreak => $assessment <= $lowBorder,
				default => false,
			};

			if (!$passes)
			{
				return null;
			}

			$assessmentIds[] = (int)$row['ID'];
			$assessments[] = $assessment;
			$bordersSeen[] = $isGoodStreak ? $highBorder : $lowBorder;
		}

		$thresholdValue = $isGoodStreak
			? max($bordersSeen)
			: min($bordersSeen)
		;

		return [
			'managerId' => $managerId,
			'assessmentIds' => $assessmentIds,
			'avgAssessment' => (int)round(array_sum($assessments) / count($assessments)),
			'thresholdValue' => $thresholdValue,
		];
	}

	/**
	 * @param list<int> $settingIds
	 *
	 * @return array<int, array{low:int, high:int}>
	 */
	private function loadScenarioBorders(array $settingIds): array
	{
		$settingIds = array_values(array_unique(array_filter(
			$settingIds,
			static fn(int $id): bool => $id > 0,
		)));
		if ($settingIds === [])
		{
			return [];
		}

		$missing = array_values(array_diff($settingIds, array_keys(self::$scenarioBordersCache)));
		if ($missing !== [])
		{
			$rows = CopilotCallAssessmentTable::query()
				->setSelect(['ID', 'LOW_BORDER', 'HIGH_BORDER'])
				->whereIn('ID', $missing)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				self::$scenarioBordersCache[$row['ID']] = [
					'low' => $row['LOW_BORDER'],
					'high' => $row['HIGH_BORDER'],
				];
			}
		}

		return array_intersect_key(self::$scenarioBordersCache, array_flip($settingIds));
	}
}

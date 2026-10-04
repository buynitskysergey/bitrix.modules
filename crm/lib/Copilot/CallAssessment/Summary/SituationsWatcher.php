<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary;

use Bitrix\Crm\Copilot\AiQualityAssessment\Entity\AiQualityAssessmentTable;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessmentTable;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Detector\RatingDetector;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\ORM\Event;

final class SituationsWatcher
{
	use Singleton;

	public function onAfterAdd(Event $event): void
	{
		$fields = $event->getParameter('fields');
		if (!is_array($fields))
		{
			return;
		}

		$activityType = (int)($fields['ACTIVITY_TYPE'] ?? 0);
		if ($activityType !== AiQualityAssessmentTable::ACTIVITY_TYPE_CALL)
		{
			return;
		}

		if (($fields['USE_IN_RATING'] ?? false) !== true)
		{
			return;
		}

		$managerId = (int)($fields['RATED_USER_ID'] ?? 0);
		if ($managerId <= 0)
		{
			return;
		}

		if (!AIManager::isCallScoringV2Enabled() || !AIManager::isAiCallProcessingEnabled())
		{
			return;
		}

		$settings = (new SettingsRepository())->load();
		if (!$settings->isEnabled)
		{
			return;
		}

		if ($settings->recipientUserIds === [] && !$settings->sendSelfDigest)
		{
			return;
		}

		$service = new SituationsService();

		$this->processStreak($service, $fields, $managerId, $settings);
		$this->processRating($service, $fields, $managerId, $settings);
	}

	private function processStreak(
		SituationsService $service,
		array $fields,
		int $managerId,
		Settings $settings,
	): void
	{
		$assessment = (int)($fields['ASSESSMENT'] ?? 0);
		$settingsId = (int)($fields['ASSESSMENT_SETTING_ID'] ?? 0);

		[$lowBorder, $highBorder] = $this->lookupBorders($settingsId);

		if ($assessment >= $highBorder)
		{
			$situation = Situation::GoodStreak;
		}
		elseif ($assessment <= $lowBorder)
		{
			$situation = Situation::BadStreak;
		}
		else
		{
			return;
		}

		$service->processStreakForManager($situation, $managerId, $settings);
	}

	private function processRating(
		SituationsService $service,
		array $fields,
		int $managerId,
		Settings $settings,
	): void
	{
		$currentRating = (int)($fields['ASSESSMENT_AVG'] ?? 0);

		$previousRating = (new RatingDetector())->getPreviousRating($managerId);

		foreach ([Situation::RatingDropped, Situation::RatingRaised] as $situation)
		{
			$service->processRatingForManager($situation, $managerId, $currentRating, $previousRating, $settings);
		}
	}

	/**
	 * @return array{0:int, 1:int} [LOW_BORDER, HIGH_BORDER]
	 */
	private function lookupBorders(int $settingsId): array
	{
		$defaultBorders = [
			CallAssessmentItem::LOW_BORDER_DEFAULT,
			CallAssessmentItem::HIGH_BORDER_DEFAULT,
		];

		if ($settingsId <= 0)
		{
			return $defaultBorders;
		}

		$row = CopilotCallAssessmentTable::query()
			->setSelect(['LOW_BORDER', 'HIGH_BORDER'])
			->where('ID', $settingsId)
			->setLimit(1)
			->fetch()
		;

		if (!isset($row['LOW_BORDER'], $row['HIGH_BORDER']))
		{
			return $defaultBorders;
		}

		return [
			$row['LOW_BORDER'],
			$row['HIGH_BORDER'],
		];
	}
}

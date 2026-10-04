<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary;

use Bitrix\Crm\Copilot\CallAssessment\Summary\Processor\RatingSituationProcessor;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Processor\StreakSituationProcessor;

final class SituationsService
{
	public function __construct(
		private readonly ?StreakSituationProcessor $streakProcessor = null,
		private readonly ?RatingSituationProcessor $ratingProcessor = null,
	) {}

	public function processStreakForManager(Situation $situation, int $managerId, Settings $settings): void
	{
		($this->streakProcessor ?? new StreakSituationProcessor())
			->process($situation, $managerId, $settings)
		;
	}

	public function processRatingForManager(
		Situation $situation,
		int $managerId,
		int $currentRating,
		?int $previousRating,
		Settings $settings,
	): void
	{
		($this->ratingProcessor ?? new RatingSituationProcessor())
			->process($situation, $managerId, $currentRating, $previousRating, $settings)
		;
	}
}

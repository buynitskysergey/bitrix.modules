<?php

namespace Bitrix\Crm\Integration\AI\Dto\Scoring;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\ObjectCollectionField;

class ScoreCallV2Payload extends Dto
{
	/** @var ScoringCriteriaV2[] */
	public array $criteriaScores = [];
	public ?string $recommendations = null;

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName) {
			'criteriaScores' => new Caster\CollectionCaster(new Caster\ObjectCaster(ScoringCriteriaV2::class)),
			default => null,
		};
	}

	protected function getValidators(array $fields): array
	{
		// An empty scoring response (no criteria scores and no recommendations) is a valid outcome
		// for calls that strongly deviate from the script. It must not be rejected as a validation
		// error: the job finishes successfully and is handled by the empty-result timeline branch
		// (Operation\ScoreCallV2::onAfterSuccessfulJobFinish -> Controller::onCallScoringEmptyResult).
		return [
			new ObjectCollectionField($this, 'criteriaScores'),
		];
	}
}

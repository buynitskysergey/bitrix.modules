<?php

namespace Bitrix\Crm\Integration\AI\Dto\Scoring;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\StringField;

final class ScoringCriteriaV2 extends Dto
{
	public string $criterion_name;
	public ?bool $met = null;
	public ?string $comment = null;
	public ?string $description = null;

	protected function getValidators(array $fields): array
	{
		return [
			new NotEmptyField($this, 'criterion_name'),
			new StringField($this, 'criterion_name'),
		];
	}
}

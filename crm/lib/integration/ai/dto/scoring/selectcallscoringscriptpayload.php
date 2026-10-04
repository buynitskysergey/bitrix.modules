<?php

namespace Bitrix\Crm\Integration\AI\Dto\Scoring;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;

final class SelectCallScoringScriptPayload extends Dto
{
	public int $scriptId;
	public int $confidence;
	public string $rationale;
	public ?string $confidenceBreakdown = null; // @todo maybe temporary

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'scriptId'),
			new RequiredField($this, 'confidence'),
			new RequiredField($this, 'rationale'),
			new IntegerField($this, 'scriptId'),
			new IntegerField($this, 'confidence'),
			new StringField($this, 'rationale'),
		];
	}
}

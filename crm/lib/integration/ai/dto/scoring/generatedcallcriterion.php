<?php

namespace Bitrix\Crm\Integration\AI\Dto\Scoring;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\StringField;

final class GeneratedCallCriterion extends Dto
{
	public string $name;
	public string $description;

	protected function getValidators(array $fields): array
	{
		return [
			new NotEmptyField($this, 'name'),
			new StringField($this, 'name'),
			new NotEmptyField($this, 'description'),
			new StringField($this, 'description'),
		];
	}
}

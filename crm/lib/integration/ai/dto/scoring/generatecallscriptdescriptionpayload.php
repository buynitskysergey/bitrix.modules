<?php

namespace Bitrix\Crm\Integration\AI\Dto\Scoring;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\StringField;

final class GenerateCallScriptDescriptionPayload extends Dto
{
	public ?string $name = null;
	public ?string $description = null;

	protected function getValidators(array $fields): array
	{
		return [
			new StringField($this, 'name'),
			new StringField($this, 'description'),
		];
	}
}

<?php

namespace Bitrix\Crm\Integration\AI\Dto\Scoring;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\ObjectCollectionField;
use Bitrix\Crm\Dto\Validator\StringField;

final class GenerateCallCriteriaPayload extends Dto
{
	public ?string $name = null;
	public ?string $description = null;
	/** @var GeneratedCallCriterion[] */
	public array $newCriteria = [];

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName) {
			'newCriteria' => new Caster\CollectionCaster(new Caster\ObjectCaster(GeneratedCallCriterion::class)),
			default => null,
		};
	}

	protected function getValidators(array $fields): array
	{
		return [
			new StringField($this, 'name'),
			new StringField($this, 'description'),
			new ObjectCollectionField($this, 'newCriteria'),
		];
	}
}

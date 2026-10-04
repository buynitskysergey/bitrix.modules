<?php

namespace Bitrix\Crm\Integration\AI\Dto\Scoring;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\ObjectCollectionField;
use Bitrix\Crm\Dto\Validator\StringField;

final class CallScriptCreatorPayload extends Dto
{
	public ?string $name = null;
	public ?string $description = null;
	/** @var GeneratedCallCriterion[] */
	public array $newCriteria = [];
	/** @var string[] */
	public array $removedCriteria = [];
	/** @var GeneratedCallCriterion[] */
	public array $updatedCriteria = [];

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName) {
			'newCriteria',
			'updatedCriteria' => new Caster\CollectionCaster(new Caster\ObjectCaster(GeneratedCallCriterion::class)),
			'removedCriteria' => new Caster\RawArrayCaster(),
			default => null,
		};
	}

	protected function getValidators(array $fields): array
	{
		return [
			new StringField($this, 'name'),
			new StringField($this, 'description'),
			new ObjectCollectionField($this, 'newCriteria'),
			new ObjectCollectionField($this, 'updatedCriteria'),
		];
	}
}

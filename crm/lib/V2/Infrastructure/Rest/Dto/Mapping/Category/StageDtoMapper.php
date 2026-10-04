<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Category;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Category\StageDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Category\StageInputDto;
use Bitrix\Crm\V2\Public\Entity\Category\Stage;
use Bitrix\Crm\V2\Public\Entity\Category\StageCollection;
use Bitrix\Crm\V2\Public\Entity\Category\StageSemantics;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Structure\Structure;

/**
 * Translates between the stage contracts of the family and the public stage of the domain.
 *
 * The mapper holds nothing: a stage does not carry the entity type it belongs to - the commands of
 * the domain are told it separately - and the contract of a stage is the same one whatever that type
 * is. Which stage a write means is said by the request rather than by its fields, so the identifier
 * is left to the controller here too.
 *
 * A write carries the fields the request has set and only them. Which fields a request may carry at
 * all is settled before the mapper by the contract itself ({@see StageDto}, {@see StageInputDto}),
 * so a read-only field never reaches here and a semantics outside the three is refused before it is
 * read back as one. Writable fields are non-nullable in the contract, which keeps `null` out of a
 * write - for this domain `null` means "no value" rather than "clear the stored one", and an empty
 * colour is how a stored one is dropped.
 */
final class StageDtoMapper
{
	public function getStageByDto(Dto $dto): Stage
	{
		$stage = new Stage();
		foreach ($dto->toArray(true) as $propertyName => $value)
		{
			$this->applyFieldToStage($stage, $propertyName, $value);
		}

		return $stage;
	}

	/**
	 * @param class-string<Dto> $dtoClass
	 */
	public function getDtoByStage(Stage $stage, string $dtoClass): Dto
	{
		$dto = $dtoClass::create();
		Structure::addDto($dto);

		/** @var DtoField $field */
		foreach ($dto->getFields() as $field)
		{
			$propertyName = $field->getPropertyName();
			$dto->{$propertyName} = $this->getStageFieldValue($stage, $propertyName);
		}

		return $dto;
	}

	/**
	 * @param class-string<Dto> $dtoClass
	 */
	public function getDtoCollectionByStages(StageCollection $stages, string $dtoClass): DtoCollection
	{
		$collection = new DtoCollection($dtoClass);
		foreach ($stages as $stage)
		{
			$collection->add($this->getDtoByStage($stage, $dtoClass));
		}

		return $collection;
	}

	/**
	 * The stages of a group replacement, in the order the request put them: that order is what says
	 * where a stage stands, and a stage sent without an identifier is one the replacement creates.
	 */
	public function getStageCollectionByInputDtos(DtoCollection $dtos): StageCollection
	{
		$stages = new StageCollection();
		foreach ($dtos as $dto)
		{
			$stages->add($this->getStageByInputDto($dto));
		}

		return $stages;
	}

	private function getStageByInputDto(Dto $dto): Stage
	{
		$stage = new Stage();
		foreach ($dto->toArray(true) as $propertyName => $value)
		{
			match ($propertyName)
			{
				Stage::stageId => $stage->setStageId($value),
				default => $this->applyFieldToStage($stage, $propertyName, $value),
			};
		}

		return $stage;
	}

	private function applyFieldToStage(Stage $stage, string $propertyName, mixed $value): void
	{
		match ($propertyName)
		{
			Stage::categoryId => $stage->setCategoryId($value),
			Stage::name => $stage->setName($value),
			Stage::color => $stage->setColor($value),
			Stage::semantics => $stage->setSemantics(StageSemantics::from($value)),
			Stage::sort => $stage->setSort($value),
			default => null,
		};
	}

	private function getStageFieldValue(Stage $stage, string $propertyName): mixed
	{
		return match ($propertyName)
		{
			Stage::stageId => $stage->getStageId(),
			Stage::categoryId => $stage->getCategoryId(),
			Stage::name => $stage->getName(),
			Stage::color => $stage->getColor(),
			Stage::semantics => $stage->getSemantics()?->value,
			Stage::sort => $stage->getSort(),
			Stage::isSystem => $stage->getIsSystem(),
			default => null,
		};
	}
}

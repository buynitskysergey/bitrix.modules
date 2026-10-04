<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Category;

use Bitrix\Crm\V2\Public\Entity\Category\Category;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryCollection;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Structure\Structure;

/**
 * Translates between the category DTO of a route and the public category of the domain.
 *
 * The entity type comes from the route rather than from the request, so it is the state of the
 * mapper: a category is meaningless without it, and a write is never offered it.
 *
 * A write carries the fields the request has set and only them. Which of the DTO fields a request
 * may carry at all is settled before the mapper by the generated contract
 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Category\CategoryDtoGenerator}), so a field that is
 * read-only for this entity type never reaches here. Writable fields are non-nullable in the
 * contract, which keeps `null` out of a write - for this domain `null` means "no value" rather than
 * "clear the stored one".
 */
final class CategoryDtoMapper
{
	public function __construct(
		private readonly EntityType $entityType,
	)
	{
	}

	public function getCategoryByDto(Dto $dto): Category
	{
		$category = (new Category())->setEntityTypeId($this->entityType->getId());
		foreach ($dto->toArray(true) as $propertyName => $value)
		{
			$this->applyFieldToCategory($category, $propertyName, $value);
		}

		return $category;
	}

	/**
	 * @param class-string<Dto> $dtoClass
	 */
	public function getDtoByCategory(Category $category, string $dtoClass): Dto
	{
		$dto = $dtoClass::create();
		Structure::addDto($dto);

		/** @var DtoField $field */
		foreach ($dto->getFields() as $field)
		{
			$propertyName = $field->getPropertyName();
			$dto->{$propertyName} = $this->getCategoryFieldValue($category, $propertyName);
		}

		return $dto;
	}

	/**
	 * @param class-string<Dto> $dtoClass
	 */
	public function getDtoCollectionByCategories(CategoryCollection $categories, string $dtoClass): DtoCollection
	{
		$collection = new DtoCollection($dtoClass);
		foreach ($categories as $category)
		{
			$collection->add($this->getDtoByCategory($category, $dtoClass));
		}

		return $collection;
	}

	private function applyFieldToCategory(Category $category, string $propertyName, mixed $value): void
	{
		match ($propertyName)
		{
			Category::name => $category->setName($value),
			Category::sort => $category->setSort($value),
			Category::isDefault => $category->setIsDefault($value),
			default => null,
		};
	}

	private function getCategoryFieldValue(Category $category, string $propertyName): mixed
	{
		return match ($propertyName)
		{
			Category::id => $category->getId(),
			Category::name => $category->getName(),
			Category::sort => $category->getSort(),
			Category::isDefault => $category->getIsDefault(),
			Category::isSystem => $category->getIsSystem(),
			Category::code => $category->getCode(),
			default => null,
		};
	}
}

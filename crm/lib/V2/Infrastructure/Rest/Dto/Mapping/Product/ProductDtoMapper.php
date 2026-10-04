<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Product;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Product\ProductDto;
use Bitrix\Crm\V2\Internal\Entity\Product\ProductCard;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * Builds {@see ProductDto} out of the internal {@see ProductCard}. One direction only - the method
 * behind this contract reads, and there is no write path to map back into.
 *
 * The external names and the internal ones agree field by field, so the correspondence below is a
 * plain list rather than a translation. It is written out in full on purpose: it is the one place where
 * "the response carries exactly the published fields" can be checked at a glance.
 *
 * `select` is honoured twice over: the read loads the asked-for fields and nothing besides
 * ({@see ProductListRequestMapper::mapSelect()} resolves the list), and a property the client did not ask
 * for is never filled here and therefore stays out of the response entirely.
 *
 * {@see ProductCard} is an internal type and is used here as a transitional exception: the scenario
 * behind this method is a single read of a catalog card, and a public contract of the catalog would be
 * a refactoring out of all proportion to it. The dependency stays inside REST and carries no
 * compatibility promise.
 */
final class ProductDtoMapper
{
	/**
	 * @param ProductCard[] $cards
	 * @param string[] $selectedFieldNames {@see ProductListRequestMapper::mapSelect()}
	 */
	public function getDtoCollectionByProductCards(array $cards, array $selectedFieldNames): DtoCollection
	{
		$collection = new DtoCollection(ProductDto::class);
		foreach ($cards as $card)
		{
			$collection->add(self::createDtoByCard($card, $selectedFieldNames));
		}

		return $collection;
	}

	/**
	 * @param string[] $selectedFieldNames
	 */
	private static function createDtoByCard(ProductCard $card, array $selectedFieldNames): ProductDto
	{
		/** @var ProductDto $dto */
		$dto = ProductDto::create();
		$values = self::getCardValues($card);
		foreach ($selectedFieldNames as $dtoFieldName)
		{
			if (!isset($dto->getFields()[$dtoFieldName]))
			{
				continue;
			}

			// Writes into the field rather than into the property, so an unselected property stays
			// uninitialized and drops out of the response.
			$dto->__set($dtoFieldName, $values[$dtoFieldName] ?? null);
		}

		return $dto;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function getCardValues(ProductCard $card): array
	{
		return [
			ProductCard::FIELD_ID => $card->id,
			ProductCard::FIELD_CATALOG_ID => $card->catalogId,
			ProductCard::FIELD_NAME => $card->name,
			ProductCard::FIELD_CODE => $card->code,
			ProductCard::FIELD_DESCRIPTION => $card->description,
			ProductCard::FIELD_ACTIVE => $card->active,
			ProductCard::FIELD_SECTION_ID => $card->sectionId,
			ProductCard::FIELD_SORT => $card->sort,
			ProductCard::FIELD_XML_ID => $card->xmlId,
			ProductCard::FIELD_PRICE => $card->price,
			ProductCard::FIELD_CURRENCY_ID => $card->currencyId,
			ProductCard::FIELD_VAT_ID => $card->vatId,
			ProductCard::FIELD_VAT_INCLUDED => $card->vatIncluded,
			ProductCard::FIELD_MEASURE_CODE => $card->measureCode,
			ProductCard::FIELD_CREATED_TIME => $card->createdTime,
			ProductCard::FIELD_UPDATED_TIME => $card->updatedTime,
			ProductCard::FIELD_CREATED_BY_ID => $card->createdById,
			ProductCard::FIELD_UPDATED_BY_ID => $card->updatedById,
		];
	}
}

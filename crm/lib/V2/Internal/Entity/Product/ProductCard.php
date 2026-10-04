<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Product;

use Bitrix\Main\Type\DateTime;

/**
 * A card of the CRM product catalog - the catalogue entry itself, not its use inside an Item. A product
 * row has an owner, a quantity and a discount; a card has none of those and never will.
 *
 * A card is the element of the CRM catalog iblock, which carries its descriptive fields, plus what the
 * catalog module keeps about the same identifier - the price, the tax and the measure. It is therefore
 * assembled from two sources at once and is read-only by construction - {@see \Bitrix\Crm\V2\Internal\Repository\Product\ProductRepository}
 * is the only place that puts one together. A field the caller did not ask for is not read and carries
 * the empty value of its type.
 *
 * The `FIELD_*` names are the vocabulary the repository, the iblock source and the REST mapper share:
 * filters and ordering are expressed in them, and the published DTO uses the same names.
 *
 * @internal
 */
final readonly class ProductCard
{
	public const FIELD_ID = 'id';
	public const FIELD_CATALOG_ID = 'catalogId';
	public const FIELD_NAME = 'name';
	public const FIELD_CODE = 'code';
	public const FIELD_DESCRIPTION = 'description';
	public const FIELD_ACTIVE = 'active';
	public const FIELD_SECTION_ID = 'sectionId';
	public const FIELD_SORT = 'sort';
	public const FIELD_XML_ID = 'xmlId';
	public const FIELD_PRICE = 'price';
	public const FIELD_CURRENCY_ID = 'currencyId';
	public const FIELD_VAT_ID = 'vatId';
	public const FIELD_VAT_INCLUDED = 'vatIncluded';
	public const FIELD_MEASURE_CODE = 'measureCode';
	public const FIELD_CREATED_TIME = 'createdTime';
	public const FIELD_UPDATED_TIME = 'updatedTime';
	public const FIELD_CREATED_BY_ID = 'createdById';
	public const FIELD_UPDATED_BY_ID = 'updatedById';

	/**
	 * @param float|null $price base price of the card; `null` when the catalog keeps no price for it.
	 * @param int|null $measureCode classifier code of the measure, not the id of the measure record.
	 */
	public function __construct(
		public int $id,
		public int $catalogId,
		public string $name,
		public ?string $code,
		public ?string $description,
		public bool $active,
		public ?int $sectionId,
		public int $sort,
		public ?string $xmlId,
		public ?float $price,
		public ?string $currencyId,
		public ?int $vatId,
		public bool $vatIncluded,
		public ?int $measureCode,
		public ?DateTime $createdTime,
		public ?DateTime $updatedTime,
		public ?int $createdById,
		public ?int $updatedById,
	)
	{
	}
}

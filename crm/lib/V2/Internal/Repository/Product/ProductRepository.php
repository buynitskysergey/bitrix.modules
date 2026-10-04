<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Product;

use Bitrix\Crm\Product\Catalog;
use Bitrix\Crm\V2\Internal\Entity\Product\ProductCard;
use Bitrix\Crm\V2\Internal\Integration\Catalog\ProductDictionary;
use Bitrix\Crm\V2\Internal\Integration\IBlock\ProductCardSource;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Type\DateTime;

/**
 * The single point the new layer reads the cards of the CRM product catalog through.
 *
 * A card is assembled from two sources that know nothing of each other: the iblock element carries the
 * descriptive fields ({@see ProductCardSource}), the catalog module the commercial ones
 * ({@see ProductDictionary}). Both are read for the whole page at once - a card at a time would cost a
 * handful of queries per card, which is how the legacy list reads and why it does not scale - and neither
 * is read at all for a field the caller did not ask for: the commercial half of a card costs queries of
 * its own, and a description costs a text column on every row of the page.
 *
 * Read-only by design: cards are written through the legacy catalog API, which this class does not
 * touch. Rights are not checked here either - the single gate of this scenario is the portal
 * administrator check of the REST controller, and the catalog rights over prices and discounts are a
 * different notion that must not be mixed into it.
 *
 * ORM objects never leave the class, and neither does the storage vocabulary: filters and ordering are
 * expressed in {@see ProductCard} field names.
 *
 * @internal
 */
final class ProductRepository
{
	/** The half of a card the catalog module answers for, and the only reason to ask it anything. */
	private const CATALOG_FIELDS = [
		ProductCard::FIELD_PRICE,
		ProductCard::FIELD_CURRENCY_ID,
		ProductCard::FIELD_VAT_ID,
		ProductCard::FIELD_VAT_INCLUDED,
		ProductCard::FIELD_MEASURE_CODE,
	];

	public function __construct(
		private readonly ProductCardSource $cardSource = new ProductCardSource(),
		private readonly ProductDictionary $catalog = new ProductDictionary(),
	)
	{
	}

	/**
	 * A page of cards, ordered as asked. Both the filter and the ordering are applied in SQL: nothing is
	 * dropped after the fact, so the page and the order stay what the client asked for.
	 *
	 * @param ConditionTree|null $filter conditions whose columns are {@see ProductCard} field names.
	 * @param array<string, string> $order {@see ProductCard} field name => `ASC`/`DESC`; the primary key
	 *        settles whatever the ordering leaves equal, so the pages of one read do not overlap.
	 * @param string[] $select the {@see ProductCard} fields the caller needs; an empty list means all of
	 *        them. A field left out is neither read nor asked of the catalog, and the card carries the
	 *        empty value of its type in its place - the caller asked not to know.
	 * @param int|null $catalogId the catalog of the CRM to read; `null` reads the default one. Cards live
	 *        in one catalog at a time, so this is a choice rather than a condition.
	 * @return ProductCard[]
	 * @throws \Bitrix\Main\LoaderException iblock or catalog is missing - a card cannot be answered
	 *         without either of them.
	 * @throws ArgumentException a filter or an ordering over a field that is not readable, or a catalog
	 *         that is not one of the CRM.
	 */
	public function findAll(
		?ConditionTree $filter = null,
		array $order = [],
		?int $limit = null,
		int $offset = 0,
		array $select = [],
		?int $catalogId = null,
	): array
	{
		// The catalogs of the CRM are iblocks, so without the module the resolution below cannot tell an
		// absent catalog from an unanswerable question - and it would answer the first.
		Loader::requireModule('iblock');

		$readCatalogId = self::resolveCatalogId($catalogId);
		if ($readCatalogId <= 0)
		{
			// No catalogue of the CRM on this portal, so no card of it either. Answered as an empty page
			// rather than by creating the catalog: a read has no business installing one.
			return [];
		}

		$fieldsById = $this->cardSource->getCardFields($readCatalogId, $filter, $order, $limit, $offset, $select);
		if ($fieldsById === [])
		{
			return [];
		}

		$catalogFieldsById = self::isCatalogNeeded($select)
			? $this->catalog->getPricesAndSettings(array_keys($fieldsById))
			: []
		;

		$cards = [];
		foreach ($fieldsById as $cardId => $fields)
		{
			$cards[] = self::createCard($fields, $catalogFieldsById[$cardId] ?? []);
		}

		return $cards;
	}

	/**
	 * The catalog the cards are read from: the one that was chosen, or the default catalog of the CRM when
	 * none was.
	 *
	 * A chosen catalog has to be one of the CRM - a row of `b_crm_catalog`. Portals have more than one: a
	 * catalog bound to an external source is a catalog of the CRM as much as the default one is, while the
	 * rest of the iblocks of the portal are none of this read's business.
	 *
	 * Two things this read does differently from the acting `CCrmProduct::GetList()`, both on purpose:
	 *
	 * - a catalog that is not one of the CRM is refused rather than quietly replaced by the default. A
	 *   client that named it asked for a page this read will never answer, and an empty page reads the same
	 *   as an empty catalog;
	 * - a catalog is chosen for every read, while the acting one chooses only when it was not asked by `ID`:
	 *   it drops the predicate over `IBLOCK_ID` altogether when the filter carries `ID` and no
	 *   `CATALOG_ID`. The predicate is what the index plan of a page rests on, so it stays here; the price
	 *   is that a card of a second catalog of the CRM is reached by naming that catalog and not by its
	 *   identifier alone.
	 *
	 * Zero means the portal has no catalog of the CRM at all. Never created here, unlike in
	 * `CCrmCatalog::EnsureDefaultExists()`: a portal without a catalogue answers with no cards rather than
	 * gets one installed by a list request.
	 *
	 * @throws ArgumentException
	 */
	private static function resolveCatalogId(?int $catalogId): int
	{
		if ($catalogId === null)
		{
			return (int)(Catalog::getDefaultId() ?? 0);
		}

		if ($catalogId <= 0 || !\CCrmCatalog::Exists($catalogId))
		{
			throw new ArgumentException(
				sprintf('`%d` is not a catalog of the CRM', $catalogId),
				ProductCard::FIELD_CATALOG_ID,
			);
		}

		return $catalogId;
	}

	/**
	 * @param string[] $select
	 */
	private static function isCatalogNeeded(array $select): bool
	{
		return $select === [] || array_intersect($select, self::CATALOG_FIELDS) !== [];
	}

	/**
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $catalogFields
	 */
	private static function createCard(array $fields, array $catalogFields): ProductCard
	{
		return new ProductCard(
			id: (int)$fields[ProductCard::FIELD_ID],
			catalogId: (int)$fields[ProductCard::FIELD_CATALOG_ID],
			name: (string)$fields[ProductCard::FIELD_NAME],
			code: self::toNullableString($fields[ProductCard::FIELD_CODE]),
			description: self::toNullableString($fields[ProductCard::FIELD_DESCRIPTION]),
			active: (bool)$fields[ProductCard::FIELD_ACTIVE],
			sectionId: self::toNullableInt($fields[ProductCard::FIELD_SECTION_ID]),
			sort: (int)$fields[ProductCard::FIELD_SORT],
			xmlId: self::toNullableString($fields[ProductCard::FIELD_XML_ID]),
			price: self::toNullableFloat($catalogFields[ProductDictionary::KEY_PRICE] ?? null),
			currencyId: self::toNullableString($catalogFields[ProductDictionary::KEY_CURRENCY_ID] ?? null),
			vatId: self::toNullableInt($catalogFields[ProductDictionary::KEY_VAT_ID] ?? null),
			vatIncluded: (bool)($catalogFields[ProductDictionary::KEY_VAT_INCLUDED] ?? false),
			measureCode: self::toNullableInt($catalogFields[ProductDictionary::KEY_MEASURE_CODE] ?? null),
			createdTime: self::toNullableDateTime($fields[ProductCard::FIELD_CREATED_TIME]),
			updatedTime: self::toNullableDateTime($fields[ProductCard::FIELD_UPDATED_TIME]),
			createdById: self::toNullableInt($fields[ProductCard::FIELD_CREATED_BY_ID]),
			updatedById: self::toNullableInt($fields[ProductCard::FIELD_UPDATED_BY_ID]),
		);
	}

	private static function toNullableString(mixed $value): ?string
	{
		return $value === null ? null : (string)$value;
	}

	private static function toNullableInt(mixed $value): ?int
	{
		return $value === null ? null : (int)$value;
	}

	private static function toNullableFloat(mixed $value): ?float
	{
		return $value === null ? null : (float)$value;
	}

	private static function toNullableDateTime(mixed $value): ?DateTime
	{
		return $value instanceof DateTime ? $value : null;
	}
}

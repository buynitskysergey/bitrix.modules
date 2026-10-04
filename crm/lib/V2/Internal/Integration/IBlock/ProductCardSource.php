<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\IBlock;

use Bitrix\Crm\V2\Internal\Entity\Product\ProductCard;
use Bitrix\Iblock\ElementTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;

/**
 * The iblock half of a CRM product card, and the only place the new layer reads `b_iblock_element`.
 *
 * A card of the CRM catalog **is** an element of a CRM catalog iblock: the element carries every field
 * a card publishes, the identifier included, and the iblock it belongs to is what makes it a card of the
 * CRM rather than an element of some other catalogue of the portal. It is also the reason `b_crm_product`
 * takes no part here: the register is written by nothing but the import from a social network, so a read
 * that started from it would answer an empty list on a portal with a full catalog.
 *
 * Which catalog that is, and whether it is a catalog of the CRM at all, is none of this class's business:
 * the caller resolves it ({@see \Bitrix\Crm\V2\Internal\Repository\Product\ProductRepository}) and this
 * class reads the iblock it was named. Exactly one catalog is read at a time and the predicate over it is
 * always in the query - the index plan of a page rests on it, and where that leaves this read next to the
 * acting one is written down where the choice is made.
 *
 * Filtering, ordering and the page are all applied in SQL, in the vocabulary of {@see ProductCard}: a
 * field the storage cannot answer is refused rather than filtered out afterwards, because filtering
 * after the fact breaks both the order and the page.
 *
 * Iblock types never leave this class - rows come back as plain values keyed by card field names.
 *
 * @internal
 */
final class ProductCardSource
{
	/**
	 * Every card field the iblock element answers for, and its column. The commercial fields - price,
	 * currency, tax and measure - are not here: they belong to the catalog module.
	 */
	private const COLUMN_BY_FIELD = [
		ProductCard::FIELD_ID => 'ID',
		ProductCard::FIELD_CATALOG_ID => 'IBLOCK_ID',
		ProductCard::FIELD_NAME => 'NAME',
		ProductCard::FIELD_CODE => 'CODE',
		ProductCard::FIELD_DESCRIPTION => 'DETAIL_TEXT',
		ProductCard::FIELD_ACTIVE => 'ACTIVE',
		ProductCard::FIELD_SECTION_ID => 'IBLOCK_SECTION_ID',
		ProductCard::FIELD_SORT => 'SORT',
		ProductCard::FIELD_XML_ID => 'XML_ID',
		ProductCard::FIELD_CREATED_TIME => 'DATE_CREATE',
		ProductCard::FIELD_UPDATED_TIME => 'TIMESTAMP_X',
		ProductCard::FIELD_CREATED_BY_ID => 'CREATED_BY',
		ProductCard::FIELD_UPDATED_BY_ID => 'MODIFIED_BY',
	];

	/**
	 * A page of cards, ordered as asked and read in a single query.
	 *
	 * @param int $catalogId the iblock the cards are read from, already resolved by the caller to a catalog
	 *        of the CRM.
	 * @param ConditionTree|null $filter conditions whose columns are {@see ProductCard} field names.
	 * @param array<string, string> $order {@see ProductCard} field name => `ASC`/`DESC`.
	 * @param string[] $fields the {@see ProductCard} fields to read; an empty list means all of them, and
	 *        a field of another source is simply not one of these. A field left out comes back as `null`.
	 * @return array<int, array<string, mixed>> card id => field values, keyed by {@see ProductCard}
	 *         field names, in the order of the query.
	 * @throws \Bitrix\Main\LoaderException the iblock module is mandatory: without it a card has no
	 *         name, no code and no dates, and there is nothing to answer with.
	 * @throws ArgumentException a filter or an ordering over a field the storage does not answer for.
	 */
	public function getCardFields(
		int $catalogId,
		?ConditionTree $filter = null,
		array $order = [],
		?int $limit = null,
		int $offset = 0,
		array $fields = [],
	): array
	{
		Loader::requireModule('iblock');

		$query = ElementTable::query()->setSelect(self::buildSelect($fields));
		$query->where(self::COLUMN_BY_FIELD[ProductCard::FIELD_CATALOG_ID], $catalogId);
		// A business process keeps its drafts and its history as elements of their own, and the acting
		// read counts neither of them as a product.
		$query->where('WF_STATUS_ID', 1);
		$query->whereNull('WF_PARENT_ELEMENT_ID');

		if ($filter !== null && $filter->hasConditions())
		{
			$query->where(self::translateColumns($filter));
		}

		$query->setOrder(self::translateOrder($order));

		if ($limit !== null)
		{
			$query->setLimit($limit);
			$query->setOffset($offset);
		}

		$cards = [];
		foreach ($query->fetchAll() as $row)
		{
			$cardFields = self::readRow($row);
			$cards[$cardFields[ProductCard::FIELD_ID]] = $cardFields;
		}

		return $cards;
	}

	/**
	 * The columns to load: the ones behind the asked-for fields, and the identifier whether it was asked
	 * for or not - the page is keyed by it. A field this source does not answer for is ignored rather than
	 * refused: the commercial half of a card is a legitimate thing to ask of a repository and simply comes
	 * from elsewhere.
	 *
	 * @param string[] $fields
	 * @return string[]
	 */
	private static function buildSelect(array $fields): array
	{
		if ($fields === [])
		{
			return array_values(self::COLUMN_BY_FIELD);
		}

		$columns = [self::COLUMN_BY_FIELD[ProductCard::FIELD_ID]];
		foreach ($fields as $field)
		{
			$column = self::COLUMN_BY_FIELD[$field] ?? null;
			if ($column !== null)
			{
				$columns[] = $column;
			}
		}

		return array_values(array_unique($columns));
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed> every field of a card, the ones that were not read among them: the
	 *         caller builds one shape of a card and reads the fields it asked for out of it.
	 */
	private static function readRow(array $row): array
	{
		$fields = [];
		foreach (self::COLUMN_BY_FIELD as $field => $column)
		{
			$fields[$field] = $row[$column] ?? null;
		}
		$fields[ProductCard::FIELD_ID] = (int)$fields[ProductCard::FIELD_ID];
		// The column keeps a letter and the ORM declares the field boolean, so what comes back depends on
		// the converter having been applied. Both answers say the same thing and both are read here.
		$fields[ProductCard::FIELD_ACTIVE] =
			$fields[ProductCard::FIELD_ACTIVE] === true || $fields[ProductCard::FIELD_ACTIVE] === 'Y'
		;

		return $fields;
	}

	/**
	 * The same tree with card field names replaced by the columns behind them. The caller keeps its own
	 * tree untouched: a request may build it once and a rewrite in place would be visible to it.
	 *
	 * @throws ArgumentException
	 */
	private static function translateColumns(ConditionTree $filter): ConditionTree
	{
		$translated = clone $filter;
		self::translateTreeColumns($translated);

		return $translated;
	}

	/**
	 * @throws ArgumentException
	 */
	private static function translateTreeColumns(ConditionTree $tree): void
	{
		foreach ($tree->getConditions() as $condition)
		{
			if ($condition instanceof ConditionTree)
			{
				self::translateTreeColumns($condition);

				continue;
			}

			$column = $condition instanceof Condition ? $condition->getColumn() : null;
			if (!is_string($column))
			{
				throw new ArgumentException('Unsupported condition of a product card filter', 'filter');
			}

			$condition->setColumn(self::requireColumn($column));
		}
	}

	/**
	 * @param array<string, string> $order
	 * @return array<string, string> never empty: the primary key settles the order of the cards a
	 *         requested ordering leaves equal, so a page is repeatable.
	 * @throws ArgumentException
	 */
	private static function translateOrder(array $order): array
	{
		$translated = [];
		foreach ($order as $field => $direction)
		{
			$translated[self::requireColumn($field)] = $direction;
		}

		$translated[self::COLUMN_BY_FIELD[ProductCard::FIELD_ID]] ??= 'ASC';

		return $translated;
	}

	/**
	 * @throws ArgumentException
	 */
	private static function requireColumn(string $field): string
	{
		$column = self::COLUMN_BY_FIELD[$field] ?? null;
		if ($column === null)
		{
			throw new ArgumentException(sprintf('Unknown product card field `%s`', $field), 'field');
		}

		return $column;
	}
}

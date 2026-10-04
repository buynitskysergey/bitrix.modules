<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Requisite;

use Bitrix\Crm\RequisiteTable;
use Bitrix\Crm\V2\Internal\Entity\Requisite\Requisite;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * The single point the new layer reads the requisites of the whole portal through.
 *
 * **Rights are not checked here, and this class must not serve a non-administrative scenario.** The
 * only gate of the read is the portal administrator check of the REST controller above it: a
 * requisite carries no access control of its own, and the right to read one is derived from the right
 * to read its owner - which this read deliberately does not apply. Put it behind anything weaker than
 * a portal administrator and the legal details of every counterparty of the portal are open.
 *
 * Owners are limited to contacts and companies. That belongs to the contract of the read rather than
 * to the storage, so it is applied here and cannot be widened by the caller.
 *
 * Read-only: requisites are written through the legacy facade, which this class does not touch. There
 * is no count of the whole selection either - the answer is a page, and an incomplete page is what
 * tells the caller the selection has ended.
 *
 * ORM objects never leave the class, and neither does the storage vocabulary: the filter, the
 * ordering and the field set are all expressed in {@see Requisite} field names, and a name this class
 * does not know is refused rather than quietly dropped.
 *
 * @internal
 */
final class RequisiteRepository
{
	/** Every field of a requisite record and the column of `b_crm_requisite` behind it. */
	private const COLUMN_BY_FIELD = [
		Requisite::FIELD_ID => 'ID',
		Requisite::FIELD_OWNER_ID => 'ENTITY_ID',
		Requisite::FIELD_OWNER_TYPE_ID => 'ENTITY_TYPE_ID',
		Requisite::FIELD_PRESET_ID => 'PRESET_ID',
		Requisite::FIELD_NAME => 'NAME',
		Requisite::FIELD_ACTIVE => 'ACTIVE',
		Requisite::FIELD_XML_ID => 'XML_ID',
		Requisite::FIELD_ORIGINATOR_ID => 'ORIGINATOR_ID',
		Requisite::FIELD_SORT => 'SORT',
		Requisite::FIELD_CREATED_TIME => 'DATE_CREATE',
		Requisite::FIELD_UPDATED_TIME => 'DATE_MODIFY',
		Requisite::FIELD_CREATED_BY_ID => 'CREATED_BY_ID',
		Requisite::FIELD_UPDATED_BY_ID => 'MODIFY_BY_ID',
		Requisite::FIELD_FULL_NAME => 'RQ_NAME',
		Requisite::FIELD_FIRST_NAME => 'RQ_FIRST_NAME',
		Requisite::FIELD_LAST_NAME => 'RQ_LAST_NAME',
		Requisite::FIELD_SECOND_NAME => 'RQ_SECOND_NAME',
		Requisite::FIELD_COMPANY_TITLE => 'RQ_COMPANY_NAME',
		Requisite::FIELD_COMPANY_FULL_NAME => 'RQ_COMPANY_FULL_NAME',
		Requisite::FIELD_COMPANY_IDENTIFIER => 'RQ_COMPANY_ID',
		Requisite::FIELD_COMPANY_REGISTRATION_DATE => 'RQ_COMPANY_REG_DATE',
		Requisite::FIELD_DIRECTOR_NAME => 'RQ_DIRECTOR',
		Requisite::FIELD_ACCOUNTANT_NAME => 'RQ_ACCOUNTANT',
		Requisite::FIELD_CEO_NAME => 'RQ_CEO_NAME',
		Requisite::FIELD_CEO_POSITION => 'RQ_CEO_WORK_POS',
		Requisite::FIELD_CONTACT_PERSON => 'RQ_CONTACT',
		Requisite::FIELD_EMAIL_ADDRESS => 'RQ_EMAIL',
		Requisite::FIELD_PHONE_NUMBER => 'RQ_PHONE',
		Requisite::FIELD_FAX_NUMBER => 'RQ_FAX',
		Requisite::FIELD_IDENTIFICATION_TYPE_ID => 'RQ_IDENT_TYPE',
		Requisite::FIELD_IDENTITY_DOCUMENT_TYPE => 'RQ_IDENT_DOC',
		Requisite::FIELD_IDENTITY_DOCUMENT_SERIES => 'RQ_IDENT_DOC_SER',
		Requisite::FIELD_IDENTITY_DOCUMENT_NUMBER => 'RQ_IDENT_DOC_NUM',
		Requisite::FIELD_IDENTITY_DOCUMENT_PERSONAL_NUMBER => 'RQ_IDENT_DOC_PERS_NUM',
		Requisite::FIELD_IDENTITY_DOCUMENT_ISSUE_DATE => 'RQ_IDENT_DOC_DATE',
		Requisite::FIELD_IDENTITY_DOCUMENT_ISSUED_BY => 'RQ_IDENT_DOC_ISSUED_BY',
		Requisite::FIELD_IDENTITY_DOCUMENT_DEPARTMENT_CODE => 'RQ_IDENT_DOC_DEP_CODE',
		Requisite::FIELD_INN => 'RQ_INN',
		Requisite::FIELD_KPP => 'RQ_KPP',
		Requisite::FIELD_USRLE => 'RQ_USRLE',
		Requisite::FIELD_IFNS => 'RQ_IFNS',
		Requisite::FIELD_OGRN => 'RQ_OGRN',
		Requisite::FIELD_OGRNIP => 'RQ_OGRNIP',
		Requisite::FIELD_OKPO => 'RQ_OKPO',
		Requisite::FIELD_OKTMO => 'RQ_OKTMO',
		Requisite::FIELD_OKVED => 'RQ_OKVED',
		Requisite::FIELD_EDRPOU => 'RQ_EDRPOU',
		Requisite::FIELD_DRFO => 'RQ_DRFO',
		Requisite::FIELD_KBE => 'RQ_KBE',
		Requisite::FIELD_IIN => 'RQ_IIN',
		Requisite::FIELD_BIN => 'RQ_BIN',
		Requisite::FIELD_REGON => 'RQ_REGON',
		Requisite::FIELD_KRS => 'RQ_KRS',
		Requisite::FIELD_PESEL => 'RQ_PESEL',
		Requisite::FIELD_SIRET => 'RQ_SIRET',
		Requisite::FIELD_SIREN => 'RQ_SIREN',
		Requisite::FIELD_RCS => 'RQ_RCS',
		Requisite::FIELD_CNPJ => 'RQ_CNPJ',
		Requisite::FIELD_CPF => 'RQ_CPF',
		Requisite::FIELD_STATE_REGISTRATION => 'RQ_STATE_REG',
		Requisite::FIELD_MUNICIPAL_REGISTRATION => 'RQ_MNPL_REG',
		Requisite::FIELD_LEGAL_FORM => 'RQ_LEGAL_FORM',
		Requisite::FIELD_SHARE_CAPITAL => 'RQ_CAPITAL',
		Requisite::FIELD_TAX_REGIME_ID => 'RQ_TAX_REGIME',
		Requisite::FIELD_RESIDENCE_COUNTRY => 'RQ_RESIDENCE_COUNTRY',
		Requisite::FIELD_BASIS_DOCUMENT => 'RQ_BASE_DOC',
		Requisite::FIELD_REGISTRATION_CERTIFICATE_SERIES => 'RQ_ST_CERT_SER',
		Requisite::FIELD_REGISTRATION_CERTIFICATE_NUMBER => 'RQ_ST_CERT_NUM',
		Requisite::FIELD_REGISTRATION_CERTIFICATE_DATE => 'RQ_ST_CERT_DATE',
		Requisite::FIELD_VAT_PAYER => 'RQ_VAT_PAYER',
		Requisite::FIELD_VAT_NUMBER => 'RQ_VAT_ID',
		Requisite::FIELD_VAT_CERTIFICATE_SERIES => 'RQ_VAT_CERT_SER',
		Requisite::FIELD_VAT_CERTIFICATE_NUMBER => 'RQ_VAT_CERT_NUM',
		Requisite::FIELD_VAT_CERTIFICATE_DATE => 'RQ_VAT_CERT_DATE',
	];

	/**
	 * The owners a requisite of this read belongs to; nothing else is answered for. Public because it is
	 * part of the contract of the read rather than an implementation detail: the transport above refuses an
	 * owner type the client names, and it must refuse by this list rather than by a copy of it.
	 */
	public const OWNER_TYPE_IDS = [
		OwnerType::CONTACT,
		OwnerType::COMPANY,
	];

	// The three maps below are keyed by field name rather than listed: every field of every row of a page is
	// classified through them, and a page holds up to a thousand records of seventy-four fields.

	private const INT_FIELDS = [
		Requisite::FIELD_ID => true,
		Requisite::FIELD_OWNER_ID => true,
		Requisite::FIELD_OWNER_TYPE_ID => true,
		Requisite::FIELD_PRESET_ID => true,
		Requisite::FIELD_SORT => true,
		Requisite::FIELD_CREATED_BY_ID => true,
		Requisite::FIELD_UPDATED_BY_ID => true,
	];

	private const BOOL_FIELDS = [
		Requisite::FIELD_ACTIVE => true,
		Requisite::FIELD_VAT_PAYER => true,
	];

	private const DATE_TIME_FIELDS = [
		Requisite::FIELD_CREATED_TIME => true,
		Requisite::FIELD_UPDATED_TIME => true,
	];

	/**
	 * A page of requisites of the contacts and companies of the portal, ordered as asked. The filter,
	 * the ordering and the page are all applied in SQL: nothing is dropped after the fact, so the page
	 * and the order stay what the caller asked for.
	 *
	 * @param ConditionTree|null $filter conditions whose columns are {@see Requisite} field names. The
	 *        owner types of the read are added to it here and are not the caller's to widen.
	 * @param array<string, string> $order {@see Requisite} field name => `ASC`/`DESC`; the primary key
	 *        settles whatever the ordering leaves equal, so the pages of one read do not overlap.
	 * @param string[] $select the {@see Requisite} fields the caller needs; an empty list means all of
	 *        them. A field left out is not read from the storage at all, and the record carries `null` in
	 *        its place, which is indistinguishable from a cell the record keeps empty.
	 * @return Requisite[]
	 * @throws ArgumentException a filter, an ordering or a field set over a field that is not a field
	 *         of a requisite.
	 * @throws SystemException a failure of the query itself, or a storage that answers a value of a
	 *         field the read cannot make sense of.
	 */
	public function findAll(
		?ConditionTree $filter = null,
		array $order = [],
		?int $limit = null,
		int $offset = 0,
		array $select = [],
	): array
	{
		$query = RequisiteTable::query()->setSelect(self::translateColumns($select));

		if ($filter !== null && $filter->hasConditions())
		{
			$query->where(self::translateFilter($filter));
		}
		$query->whereIn(self::COLUMN_BY_FIELD[Requisite::FIELD_OWNER_TYPE_ID], self::OWNER_TYPE_IDS);

		$query->setOrder(self::translateOrder($order));

		if ($limit !== null)
		{
			$query->setLimit($limit);
			$query->setOffset($offset);
		}

		$requisites = [];
		foreach ($query->fetchAll() as $row)
		{
			$requisites[] = self::createRequisite($row);
		}

		return $requisites;
	}

	/**
	 * The columns to load: the ones behind the asked-for fields, and the identifier whether it was
	 * asked for or not - the page is keyed by it.
	 *
	 * @param string[] $select
	 * @return string[]
	 * @throws ArgumentException
	 */
	private static function translateColumns(array $select): array
	{
		if ($select === [])
		{
			return array_values(self::COLUMN_BY_FIELD);
		}

		$columns = [self::COLUMN_BY_FIELD[Requisite::FIELD_ID]];
		foreach ($select as $field)
		{
			$columns[] = self::requireColumn($field);
		}

		return array_values(array_unique($columns));
	}

	/**
	 * The same tree with requisite field names replaced by the columns behind them. The caller keeps
	 * its own tree untouched: a request may build it once and a rewrite in place would be visible to
	 * it.
	 *
	 * @throws ArgumentException
	 */
	private static function translateFilter(ConditionTree $filter): ConditionTree
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
				throw new ArgumentException('Unsupported condition of a requisite filter', 'filter');
			}

			$condition->setColumn(self::requireColumn($column));
		}
	}

	/**
	 * @param array<string, string> $order
	 * @return array<string, string> never empty: the primary key settles the order of the requisites a
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

		$translated[self::COLUMN_BY_FIELD[Requisite::FIELD_ID]] ??= 'ASC';

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
			throw new ArgumentException(sprintf('Unknown requisite field `%s`', $field), 'field');
		}

		return $column;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function createRequisite(array $row): Requisite
	{
		$fields = [];
		foreach (self::COLUMN_BY_FIELD as $field => $column)
		{
			$fields[$field] = self::castValue($field, $row[$column] ?? null);
		}
		$fields[Requisite::FIELD_ID] = (int)$fields[Requisite::FIELD_ID];

		// The field names of a requisite are its property names by contract, so a row becomes a record
		// by name: writing seventy-four values out one by one would be a third list to keep in step
		// with the map above and the constructor.
		return new Requisite(...$fields);
	}

	/**
	 * @throws SystemException a value of a field of a requisite the read cannot make sense of.
	 */
	private static function castValue(string $field, mixed $value): mixed
	{
		if ($value === null)
		{
			return null;
		}

		if (isset(self::INT_FIELDS[$field]))
		{
			return (int)$value;
		}

		if (isset(self::BOOL_FIELDS[$field]))
		{
			// The column keeps a letter and the ORM declares the field boolean, so what comes back
			// depends on the converter having been applied. Both answers say the same thing.
			return $value === true || $value === 'Y';
		}

		if (isset(self::DATE_TIME_FIELDS[$field]))
		{
			if (!$value instanceof DateTime)
			{
				// The converter of the ORM answers a moment in time for these columns. Anything else is a
				// storage the read has to be taught rather than an empty cell to pass on: every record would
				// answer `createdTime` and `updatedTime` as `null`, which a caller cannot tell from a cell
				// that was never filled.
				throw new SystemException(
					sprintf('Unexpected value of the requisite field `%s`: %s', $field, get_debug_type($value)),
				);
			}

			return $value;
		}

		return (string)$value;
	}
}

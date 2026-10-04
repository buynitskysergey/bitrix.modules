<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;

/**
 * Class DocumentTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> COLLECTION_ID int mandatory
 * <li> PARENT_ID int optional
 * <li> TITLE string(255) mandatory
 * <li> MARKDOWN text optional
 * <li> POSITION int mandatory
 * <li> IS_ARCHIVED bool mandatory
 * <li> IS_MAIN bool mandatory
 * <li> ARCHIVED_AT datetime optional
 * <li> ARCHIVED_BY int optional
 * <li> CONTENT_UPDATED_AT datetime optional
 * <li> MATERIALIZED_UPTO_ID int optional
 * <li> IS_DERIVED_STALE bool mandatory
 * <li> CREATED_BY int mandatory
 * <li> UPDATED_BY int mandatory
 * <li> CONTENT_FORMAT string optional default 'yjs'
 * <li> CREATED_AT datetime mandatory
 * <li> UPDATED_AT datetime mandatory
 * </ul>
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Document_Query query()
 * @method static EO_Document_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Document_Result getById($id)
 * @method static EO_Document_Result getList(array $parameters = [])
 * @method static EO_Document_Entity getEntity()
 * @method static \Bitrix\Note\Internal\Model\Document createObject($setDefaultValues = true)
 * @method static \Bitrix\Note\Internal\Model\Documents createCollection()
 * @method static \Bitrix\Note\Internal\Model\Document wakeUpObject($row)
 * @method static \Bitrix\Note\Internal\Model\Documents wakeUpCollection($rows)
 */
class DocumentTable extends DataManager
{
	const MAX_TITLE_LENGTH = 255;

	public const CONTENT_FORMAT_YJS = 'yjs';
	/** @deprecated Legacy format, read-only. New documents use CONTENT_FORMAT_YJS. */
	public const CONTENT_FORMAT_JSON = 'json';
	public const CONTENT_FORMAT_MD = 'md';

	// IS_MAIN flag values (BooleanField CHAR(1)). 'Y' marks the single per-collection
	// "main document" that carries the knowledge base description; 'N' is a regular document.
	public const IS_MAIN_YES = 'Y';
	public const IS_MAIN_NO = 'N';

	// IS_DERIVED_STALE flag values (BooleanField CHAR(1)). 'Y' means the derived projections
	// (full-text search, link index) need a rebuild; 'N' means they are in step with MARKDOWN.
	public const DERIVED_STALE_YES = 'Y';
	public const DERIVED_STALE_NO = 'N';

	public static function getTableName()
	{
		return 'b_note_document';
	}

	public static function getObjectClass(): string
	{
		return Document::class;
	}

	public static function getCollectionClass(): string
	{
		return Documents::class;
	}

	public static function getMap()
	{
		return [
			new IntegerField(
				'ID',
				[
					'primary' => true,
					'autocomplete' => true,
				],
			),
			new IntegerField(
				'COLLECTION_ID',
				[
					'required' => true,
				],
			),
			(new IntegerField('PARENT_ID'))->configureNullable(),
			// 4-byte UTF-8 (emoji) is silently truncated over the utf8mb3 MySQL
			// connection; encode to an ASCII :hex: shortcode on save, decode on fetch.
			(new StringField(
				'TITLE',
				[
					'required' => true,
					'validation' => [__CLASS__, 'validateTitle'],
				],
			))
				->addSaveDataModifier([Emoji::class, 'encode'])
				->addFetchDataModifier([Emoji::class, 'decode']),
			(new TextField(
				'MARKDOWN',
				[
					'default_value' => '',
				],
			))
				->addSaveDataModifier([Emoji::class, 'encode'])
				->addFetchDataModifier([Emoji::class, 'decode']),
			(new TextField('YJS_STATE'))->configureNullable(),
			new StringField(
				'CONTENT_FORMAT',
				[
					'default_value' => self::CONTENT_FORMAT_YJS,
				],
			),
			new IntegerField(
				'POSITION',
				[
					'required' => true,
					'default_value' => 0,
				],
			),
			new BooleanField(
				'IS_ARCHIVED',
				[
					'required' => true,
					'values' => ['N', 'Y'],
					'default_value' => 'N',
				],
			),
			new BooleanField(
				'IS_MAIN',
				[
					'required' => true,
					'values' => [self::IS_MAIN_NO, self::IS_MAIN_YES],
					'default_value' => self::IS_MAIN_NO,
				],
			),
			(new DatetimeField('ARCHIVED_AT'))->configureNullable(),
			(new IntegerField('ARCHIVED_BY'))->configureNullable(),
			// Moment the MARKDOWN projection was built (distinct from UPDATED_AT). NULL until first written.
			(new DatetimeField('CONTENT_UPDATED_AT'))->configureNullable(),
			// Internal materialization cursor: last patch id folded into MARKDOWN (yjs only).
			// Size 8 - the column is bigint in the schema (see install/migrations/tables.php).
			(new IntegerField('MATERIALIZED_UPTO_ID'))->configureNullable()->configureSize(8),
			// Dirty flag: derived projections (search, links) require a rebuild by the freshness agent.
			new BooleanField(
				'IS_DERIVED_STALE',
				[
					'required' => true,
					'values' => [self::DERIVED_STALE_NO, self::DERIVED_STALE_YES],
					'default_value' => self::DERIVED_STALE_NO,
				],
			),
			new IntegerField(
				'CREATED_BY',
				[
					'required' => true,
				],
			),
			new IntegerField(
				'UPDATED_BY',
				[
					'required' => true,
				],
			),
			new DatetimeField(
				'CREATED_AT',
				[
					'required' => true,
					'default_value' => static fn() => new DateTime(),
				],
			),
			new DatetimeField(
				'UPDATED_AT',
				[
					'required' => true,
					'default_value' => static fn() => new DateTime(),
				],
			),
			new Reference(
				'COLLECTION',
				CollectionTable::class,
				['=this.COLLECTION_ID' => 'ref.ID'],
				['join_type' => 'LEFT'],
			),
			new Reference(
				'PARENT',
				DocumentTable::class,
				['=this.PARENT_ID' => 'ref.ID'],
				['join_type' => 'LEFT'],
			),
			new Reference(
				'CREATED_BY_USER',
				\Bitrix\Main\UserTable::class,
				['=this.CREATED_BY' => 'ref.ID'],
				['join_type' => 'LEFT'],
			),
			new Reference(
				'UPDATED_BY_USER',
				\Bitrix\Main\UserTable::class,
				['=this.UPDATED_BY' => 'ref.ID'],
				['join_type' => 'LEFT'],
			),
			new Reference(
				'RECYCLE_BIN',
				RecycleBinTable::class,
				['=this.ID' => 'ref.DOCUMENT_ID'],
				['join_type' => 'LEFT'],
			),
		];
	}

	public static function validateTitle()
	{
		return [
			new LengthValidator(null, static::MAX_TITLE_LENGTH),
		];
	}
}

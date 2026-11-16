<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Type\DateTime;

/**
 * Class RecycleBinTable
 *
 * Fields:
 * <ul>
 * <li> ID bigint mandatory
 * <li> DOCUMENT_ID int mandatory
 * <li> TRASHED_AT datetime mandatory
 * <li> TRASHED_BY int mandatory
 * <li> ORIGIN string(40) mandatory
 * <li> COLLECTION_ID int mandatory
 * <li> IS_ROOT bool mandatory
 * </ul>
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_RecycleBin_Query query()
 * @method static EO_RecycleBin_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_RecycleBin_Result getById($id)
 * @method static EO_RecycleBin_Result getList(array $parameters = [])
 * @method static EO_RecycleBin_Entity getEntity()
 * @method static \Bitrix\Note\Internal\Model\RecycleBinEntry createObject($setDefaultValues = true)
 * @method static \Bitrix\Note\Internal\Model\EO_RecycleBin_Collection createCollection()
 * @method static \Bitrix\Note\Internal\Model\RecycleBinEntry wakeUpObject($row)
 * @method static \Bitrix\Note\Internal\Model\EO_RecycleBin_Collection wakeUpCollection($rows)
 */
class RecycleBinTable extends DataManager
{
	use DeleteByFilterTrait;

	public const ORIGIN_USER_DELETE = 'user_delete';
	public const ORIGIN_CASCADE_DOCUMENT = 'cascade_document';
	public const ORIGIN_CASCADE_COLLECTION_DELETED = 'cascade_collection_deleted';

	// IS_ROOT flag values (BooleanField CHAR(1)). 'Y' marks the top level of a section of the bin:
	// the document is not the description of a base and no parent of it lies in the bin beside it.
	public const IS_ROOT_YES = 'Y';
	public const IS_ROOT_NO = 'N';

	public static function getTableName()
	{
		return 'b_note_recycle_bin';
	}

	public static function getObjectClass(): string
	{
		return RecycleBinEntry::class;
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
				'DOCUMENT_ID',
				[
					'required' => true,
				],
			),
			new DatetimeField(
				'TRASHED_AT',
				[
					'required' => true,
					'default_value' => static fn() => new DateTime(),
				],
			),
			new IntegerField(
				'TRASHED_BY',
				[
					'required' => true,
				],
			),
			new StringField(
				'ORIGIN',
				[
					'required' => true,
					'validation' => static fn() => [new LengthValidator(null, 40)],
				],
			),
			// Copy of the document's knowledge base: 0 when the document has no base row to belong to.
			new IntegerField(
				'COLLECTION_ID',
				[
					'required' => true,
					'default_value' => 0,
				],
			),
			new BooleanField(
				'IS_ROOT',
				[
					'required' => true,
					'values' => [self::IS_ROOT_NO, self::IS_ROOT_YES],
					'default_value' => self::IS_ROOT_NO,
				],
			),
			new Reference(
				'DOCUMENT',
				DocumentTable::class,
				['=this.DOCUMENT_ID' => 'ref.ID'],
				['join_type' => 'LEFT'],
			),
		];
	}
}

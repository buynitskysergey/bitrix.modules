<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;

/**
 * Class DocumentVersionTable
 *
 * A content snapshot created on compact and on OverwriteDocumentContentCommand
 * (including restore/REST-overwrite). Holds only MARKDOWN + TITLE; no
 * YJS_STATE/CONTENT_FORMAT/TRIGGER_TYPE.
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> DOCUMENT_ID int mandatory
 * <li> MARKDOWN text mandatory
 * <li> TITLE string(255) mandatory
 * <li> CREATED_BY int mandatory
 * <li> CREATED_AT datetime mandatory
 * </ul>
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_DocumentVersion_Query query()
 * @method static EO_DocumentVersion_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_DocumentVersion_Result getById($id)
 * @method static EO_DocumentVersion_Result getList(array $parameters = [])
 * @method static EO_DocumentVersion_Entity getEntity()
 * @method static \Bitrix\Note\Internal\Model\EO_DocumentVersion createObject($setDefaultValues = true)
 * @method static \Bitrix\Note\Internal\Model\EO_DocumentVersion_Collection createCollection()
 * @method static \Bitrix\Note\Internal\Model\EO_DocumentVersion wakeUpObject($row)
 * @method static \Bitrix\Note\Internal\Model\EO_DocumentVersion_Collection wakeUpCollection($rows)
 */
class DocumentVersionTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_note_document_version';
	}

	public static function getMap(): array
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
			// Emoji-safe storage: encode 4-byte UTF-8 to :hex: on save, decode on fetch
			// (see DocumentTable) so history/restore/diff keep emoji intact.
			(new TextField(
				'MARKDOWN',
				[
					'required' => true,
				],
			))
				->addSaveDataModifier([Emoji::class, 'encode'])
				->addFetchDataModifier([Emoji::class, 'decode']),
			(new StringField(
				'TITLE',
				[
					'required' => true,
					'validation' => static fn() => [new LengthValidator(null, 255)],
				],
			))
				->addSaveDataModifier([Emoji::class, 'encode'])
				->addFetchDataModifier([Emoji::class, 'decode']),
			new IntegerField(
				'CREATED_BY',
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
			new Reference(
				'DOCUMENT',
				DocumentTable::class,
				['=this.DOCUMENT_ID' => 'ref.ID'],
				['join_type' => 'LEFT'],
			),
		];
	}
}

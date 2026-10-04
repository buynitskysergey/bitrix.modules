<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Data\Internal\MergeTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;

/**
 * Class NoteDocumentSearchTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> DOCUMENT_ID int mandatory unique
 * <li> BODY text mandatory
 * <li> UPDATED_AT datetime mandatory
 * </ul>
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_NoteDocumentSearch_Query query()
 * @method static EO_NoteDocumentSearch_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_NoteDocumentSearch_Result getById($id)
 * @method static EO_NoteDocumentSearch_Result getList(array $parameters = [])
 * @method static EO_NoteDocumentSearch_Entity getEntity()
 * @method static \Bitrix\Note\Internal\Model\EO_NoteDocumentSearch createObject($setDefaultValues = true)
 * @method static \Bitrix\Note\Internal\Model\EO_NoteDocumentSearch_Collection createCollection()
 * @method static \Bitrix\Note\Internal\Model\EO_NoteDocumentSearch wakeUpObject($row)
 * @method static \Bitrix\Note\Internal\Model\EO_NoteDocumentSearch_Collection wakeUpCollection($rows)
 */
final class NoteDocumentSearchTable extends DataManager
{
	use DeleteByFilterTrait;
	use MergeTrait;

	public static function getTableName()
	{
		return 'b_note_document_search';
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
					'unique' => true,
				],
			),
			// No Emoji encode/decode modifier here on purpose: rows are written via merge()
			// (raw INSERT..ON DUPLICATE KEY UPDATE), which bypasses ORM save modifiers.
			// Emoji::encode/decode for BODY is done explicitly in SearchIndexEntryMapper.
			new TextField(
				'BODY',
				[
					'required' => true,
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
				'DOCUMENT',
				DocumentTable::class,
				['=this.DOCUMENT_ID' => 'ref.ID'],
				['join_type' => 'INNER'],
			),
		];
	}
}

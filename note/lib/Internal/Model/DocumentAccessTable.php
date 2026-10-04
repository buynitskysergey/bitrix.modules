<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

/**
 * Class DocumentAccessTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_DocumentAccess_Query query()
 * @method static EO_DocumentAccess_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_DocumentAccess_Result getById($id)
 * @method static EO_DocumentAccess_Result getList(array $parameters = [])
 * @method static EO_DocumentAccess_Entity getEntity()
 * @method static \Bitrix\Note\Internal\Model\EO_DocumentAccess createObject($setDefaultValues = true)
 * @method static \Bitrix\Note\Internal\Model\EO_DocumentAccess_Collection createCollection()
 * @method static \Bitrix\Note\Internal\Model\EO_DocumentAccess wakeUpObject($row)
 * @method static \Bitrix\Note\Internal\Model\EO_DocumentAccess_Collection wakeUpCollection($rows)
 */
class DocumentAccessTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_note_document_access';
	}

	public static function getMap(): array
	{
		return [
			new IntegerField('ID', [
				'primary' => true,
				'autocomplete' => true,
			]),
			new IntegerField('DOCUMENT_ID', [
				'required' => true,
			]),
			new StringField('SUBJECT_CODE', [
				'required' => true,
			]),
			new IntegerField('LEVEL', [
				'required' => true,
			]),
			// 0 sentinel = explicit (non-inherited) grant; see DocumentAccessService::SOURCE_NONE.
			new IntegerField('SOURCE_DOCUMENT_ID', [
				'required' => true,
				'default_value' => 0,
			]),
			new IntegerField('CREATED_BY', [
				'required' => true,
			]),
			new DatetimeField('CREATED_AT', [
				'required' => true,
				'default_value' => static fn() => new DateTime(),
			]),
		];
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\Type\DateTime;

/**
 * [P3.T1 / SQL-04] One row per (document, user) — last view timestamp. Upserted on
 * awareness `join`, never appended: the composite PK is both the uniqueness
 * constraint and the merge target for DocumentViewRepository::track().
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_DocumentView_Query query()
 * @method static EO_DocumentView_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_DocumentView_Result getById($id)
 * @method static EO_DocumentView_Result getList(array $parameters = [])
 * @method static EO_DocumentView_Entity getEntity()
 * @method static \Bitrix\Note\Internal\Model\EO_DocumentView createObject($setDefaultValues = true)
 * @method static \Bitrix\Note\Internal\Model\EO_DocumentView_Collection createCollection()
 * @method static \Bitrix\Note\Internal\Model\EO_DocumentView wakeUpObject($row)
 * @method static \Bitrix\Note\Internal\Model\EO_DocumentView_Collection wakeUpCollection($rows)
 */
class DocumentViewTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_note_document_view';
	}

	public static function getMap(): array
	{
		return [
			new IntegerField('DOCUMENT_ID', [
				'primary' => true,
				'required' => true,
			]),
			new IntegerField('USER_ID', [
				'primary' => true,
				'required' => true,
			]),
			new DatetimeField('VIEWED_AT', [
				'required' => true,
				'default_value' => static fn() => new DateTime(),
			]),
		];
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DateField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

/**
 * Class ExternalAccessTable
 *
 * TOKEN is nullable on purpose: a NULL token marks a record that has never been enabled.
 * EXPIRES_AT is a calendar day, not a moment, hence DateField.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_ExternalAccess_Query query()
 * @method static EO_ExternalAccess_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_ExternalAccess_Result getById($id)
 * @method static EO_ExternalAccess_Result getList(array $parameters = [])
 * @method static EO_ExternalAccess_Entity getEntity()
 * @method static \Bitrix\Note\Internal\Model\EO_ExternalAccess createObject($setDefaultValues = true)
 * @method static \Bitrix\Note\Internal\Model\EO_ExternalAccess_Collection createCollection()
 * @method static \Bitrix\Note\Internal\Model\EO_ExternalAccess wakeUpObject($row)
 * @method static \Bitrix\Note\Internal\Model\EO_ExternalAccess_Collection wakeUpCollection($rows)
 */
class ExternalAccessTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_note_external_access';
	}

	public static function getMap(): array
	{
		return [
			new IntegerField('ID', [
				'primary' => true,
				'autocomplete' => true,
			]),
			new StringField('TOKEN'),
			new BooleanField('IS_ENABLED', [
				'required' => true,
				'values' => ['N', 'Y'],
				'default_value' => 'N',
			]),
			new IntegerField('ACCESS_LEVEL', [
				'required' => true,
				'default_value' => 10,
			]),
			new StringField('PASSWORD_HASH'),
			new DateField('EXPIRES_AT'),
			new IntegerField('CREATED_BY', [
				'required' => true,
			]),
			new DatetimeField('CREATED_AT', [
				'required' => true,
				'default_value' => static fn() => new DateTime(),
			]),
			new IntegerField('UPDATED_BY', [
				'required' => true,
			]),
			new DatetimeField('UPDATED_AT', [
				'required' => true,
				'default_value' => static fn() => new DateTime(),
			]),
		];
	}
}

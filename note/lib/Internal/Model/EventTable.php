<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Type\DateTime;

/**
 * Class EventTable
 *
 * A fact-only history record: no DETAILS/payload. VERSION_ID is a structural
 * reference to a version snapshot for content events, not "detail" data.
 *
 * Fields:
 * <ul>
 * <li> ID bigint mandatory
 * <li> SCOPE string(10) mandatory
 * <li> ENTITY_ID int mandatory
 * <li> EVENT_TYPE string(32) mandatory
 * <li> USER_ID int mandatory
 * <li> VERSION_ID int optional
 * <li> CREATED_AT datetime mandatory
 * </ul>
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Event_Query query()
 * @method static EO_Event_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Event_Result getById($id)
 * @method static EO_Event_Result getList(array $parameters = [])
 * @method static EO_Event_Entity getEntity()
 * @method static \Bitrix\Note\Internal\Model\EO_Event createObject($setDefaultValues = true)
 * @method static \Bitrix\Note\Internal\Model\EO_Event_Collection createCollection()
 * @method static \Bitrix\Note\Internal\Model\EO_Event wakeUpObject($row)
 * @method static \Bitrix\Note\Internal\Model\EO_Event_Collection wakeUpCollection($rows)
 */
class EventTable extends DataManager
{
	use DeleteByFilterTrait;

	public const SCOPE_DOCUMENT = 'document';
	public const SCOPE_COLLECTION = 'collection';

	public static function getTableName(): string
	{
		return 'b_note_event';
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
			new StringField(
				'SCOPE',
				[
					'required' => true,
					'validation' => static fn() => [new LengthValidator(null, 10)],
				],
			),
			new IntegerField(
				'ENTITY_ID',
				[
					'required' => true,
				],
			),
			new StringField(
				'EVENT_TYPE',
				[
					'required' => true,
					'validation' => static fn() => [new LengthValidator(null, 32)],
				],
			),
			new IntegerField(
				'USER_ID',
				[
					'required' => true,
				],
			),
			(new IntegerField('VERSION_ID'))->configureNullable(),
			new DatetimeField(
				'CREATED_AT',
				[
					'required' => true,
					'default_value' => static fn() => new DateTime(),
				],
			),
		];
	}
}

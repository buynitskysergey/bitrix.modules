<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Type\DateTime;

/**
 * Class RecordIntentStateTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_RecordIntentState_Query query()
 * @method static EO_RecordIntentState_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_RecordIntentState_Result getById($id)
 * @method static EO_RecordIntentState_Result getList(array $parameters = [])
 * @method static EO_RecordIntentState_Entity getEntity()
 * @method static \Bitrix\Timeman\V2\Internal\Model\EO_RecordIntentState createObject($setDefaultValues = true)
 * @method static \Bitrix\Timeman\V2\Internal\Model\EO_RecordIntentState_Collection createCollection()
 * @method static \Bitrix\Timeman\V2\Internal\Model\EO_RecordIntentState wakeUpObject($row)
 * @method static \Bitrix\Timeman\V2\Internal\Model\EO_RecordIntentState_Collection wakeUpCollection($rows)
 */
class RecordIntentStateTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_timeman_entries_intent_state';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new IntegerField('USER_ID'))
				->configureRequired(),
			(new IntegerField('FIRST_USE_AT'))
				->configureRequired()
				->configureDefaultValue(0),
			(new StringField('PERIOD_KEY'))
				->configureRequired()
				->configureDefaultValue('')
				->addValidator(new LengthValidator(null, 32)),
			(new IntegerField('TARGET_START_AT'))
				->configureRequired()
				->configureDefaultValue(0),
			(new IntegerField('PERIOD_END_AT'))
				->configureRequired()
				->configureDefaultValue(0),
			(new IntegerField('SHOW_COUNT'))
				->configureRequired()
				->configureDefaultValue(0),
			(new IntegerField('DISMISS_COUNT'))
				->configureRequired()
				->configureDefaultValue(0),
			(new IntegerField('SUPPRESSED_UNTIL'))
				->configureRequired()
				->configureDefaultValue(0),
			(new StringField('HAS_TARGET_ACTION'))
				->configureRequired()
				->configureDefaultValue('N')
				->addValidator(new LengthValidator(null, 1)),
			(new IntegerField('LAST_REGISTERED_AT'))
				->configureRequired()
				->configureDefaultValue(0),
			(new IntegerField('LAST_SHOWN_AT'))
				->configureRequired()
				->configureDefaultValue(0),
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
				->configureDefaultValue(static fn (): DateTime => new DateTime()),
			(new DatetimeField('UPDATED_AT'))
				->configureRequired()
				->configureDefaultValue(static fn (): DateTime => new DateTime()),
		];
	}
}

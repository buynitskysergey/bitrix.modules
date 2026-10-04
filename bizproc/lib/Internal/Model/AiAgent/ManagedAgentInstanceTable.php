<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Model\AiAgent;

use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstanceState;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

/**
 * Class ManagedAgentInstanceTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> IDENTITY_HASH string(64) mandatory
 * <li> SYSTEM_CODE string(50) mandatory
 * <li> CONTEXT_NAMESPACE string(64) mandatory
 * <li> CONTEXT_TYPE string(64) mandatory
 * <li> CONTEXT_ID string(128) mandatory
 * <li> USER_ID int mandatory
 * <li> TEMPLATE_ID int optional
 * <li> STATE enum mandatory
 * <li> CONFIG_FINGERPRINT string(64) mandatory
 * <li> RETRY_COUNT int mandatory default 0
 * <li> NEXT_RETRY_AT datetime optional
 * <li> LAST_ERROR_CODE string(64) optional
 * <li> CREATED_AT datetime mandatory default current datetime
 * <li> UPDATED_AT datetime mandatory default current datetime
 * </ul>
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_ManagedAgentInstance_Query query()
 * @method static EO_ManagedAgentInstance_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_ManagedAgentInstance_Result getById($id)
 * @method static EO_ManagedAgentInstance_Result getList(array $parameters = [])
 * @method static EO_ManagedAgentInstance_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentInstance_Collection wakeUpCollection($rows)
 */
class ManagedAgentInstanceTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_bp_managed_agent_instance';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new StringField('IDENTITY_HASH'))
				->configureRequired()
				->configureSize(64)
				->configureUnique()
				->addValidator(new LengthValidator(64, 64))
			,
			(new StringField('SYSTEM_CODE'))
				->configureRequired()
				->configureSize(50)
				->addValidator(new LengthValidator(1, 50))
			,
			(new StringField('CONTEXT_NAMESPACE'))
				->configureRequired()
				->configureSize(64)
				->addValidator(new LengthValidator(1, 64))
			,
			(new StringField('CONTEXT_TYPE'))
				->configureRequired()
				->configureSize(64)
				->addValidator(new LengthValidator(1, 64))
			,
			(new StringField('CONTEXT_ID'))
				->configureRequired()
				->configureSize(128)
				->addValidator(new LengthValidator(1, 128))
			,
			(new IntegerField('USER_ID'))
				->configureRequired()
			,
			(new IntegerField('TEMPLATE_ID'))
				->configureNullable()
				->configureUnique()
			,
			(new EnumField('STATE'))
				->configureRequired()
				->configureValues(array_column(ManagedAgentInstanceState::cases(), 'value'))
			,
			(new StringField('CONFIG_FINGERPRINT'))
				->configureRequired()
				->configureSize(64)
				->addValidator(new LengthValidator(64, 64))
			,
			(new IntegerField('RETRY_COUNT'))
				->configureRequired()
				->configureDefaultValue(0)
			,
			(new DatetimeField('NEXT_RETRY_AT'))
				->configureNullable()
			,
			(new StringField('LAST_ERROR_CODE'))
				->configureSize(64)
				->configureNullable()
				->addValidator(new LengthValidator(null, 64))
			,
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
				->configureDefaultValueNow()
			,
			(new DatetimeField('UPDATED_AT'))
				->configureRequired()
				->configureDefaultValueNow()
			,
		];
	}
}

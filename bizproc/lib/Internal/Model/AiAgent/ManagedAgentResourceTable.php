<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Model\AiAgent;

use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

/**
 * Class ManagedAgentResourceTable
 *
 * DATA keeps the versioned JSON payload as it is stored; encoding and decoding belong to the mapper.
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> INSTANCE_ID int mandatory
 * <li> TYPE enum mandatory
 * <li> RESOURCE_ID string(128) mandatory
 * <li> DATA text optional
 * <li> CREATED_AT datetime mandatory default current datetime
 * </ul>
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_ManagedAgentResource_Query query()
 * @method static EO_ManagedAgentResource_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_ManagedAgentResource_Result getById($id)
 * @method static EO_ManagedAgentResource_Result getList(array $parameters = [])
 * @method static EO_ManagedAgentResource_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Model\AiAgent\EO_ManagedAgentResource_Collection wakeUpCollection($rows)
 */
class ManagedAgentResourceTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_bp_managed_agent_resource';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('INSTANCE_ID'))
				->configureRequired()
			,
			(new EnumField('TYPE'))
				->configureRequired()
				->configureValues(array_column(ManagedAgentResourceType::cases(), 'value'))
			,
			(new StringField('RESOURCE_ID'))
				->configureRequired()
				->configureSize(128)
				->addValidator(new LengthValidator(1, 128))
			,
			(new TextField('DATA'))
				->configureNullable()
			,
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
				->configureDefaultValueNow()
			,
		];
	}
}

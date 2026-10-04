<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Model\LastValues;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\SystemException;

/**
 * Class WorkflowLastValuesTable
 *
 * Values of the last finished run, one row per template: TEMPLATE_ID is the primary key supplied by
 * the capture, so every capture rewrites the row. VALUES_DATA holds the JSON-encoded value map
 * ('VALUES' is a reserved SQL word).
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WorkflowLastValues_Query query()
 * @method static EO_WorkflowLastValues_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_WorkflowLastValues_Result getById($id)
 * @method static EO_WorkflowLastValues_Result getList(array $parameters = [])
 * @method static EO_WorkflowLastValues_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Model\LastValues\EO_WorkflowLastValues_Collection wakeUpCollection($rows)
 */
class WorkflowLastValuesTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_bp_workflow_last_values';
	}

	/**
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public static function getMap(): array
	{
		return [
			(new IntegerField('TEMPLATE_ID'))
				->configurePrimary()
			,
			(new DatetimeField('VERSION_KEY'))
				->configureRequired()
			,
			(new StringField('WORKFLOW_ID'))
				->configureNullable()
				->configureSize(32)
			,
			(new StringField('MODULE_ID'))
				->configureNullable()
				->configureSize(32)
			,
			(new StringField('ENTITY'))
				->configureNullable()
				->configureSize(64)
			,
			(new StringField('DOCUMENT_ID'))
				->configureNullable()
				->configureSize(128)
			,
			(new IntegerField('STATUS'))
				->configureDefaultValue(0)
			,
			(new DatetimeField('COMPLETED_AT'))
				->configureRequired()
			,
			(new DatetimeField('UPDATED_AT'))
				->configureRequired()
			,
			(new TextField('VALUES_DATA'))
				->configureRequired()
			,
		];
	}
}

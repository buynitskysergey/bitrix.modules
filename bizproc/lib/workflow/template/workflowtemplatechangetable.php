<?php

namespace Bitrix\Bizproc\Workflow\Template;

use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;

/**
 * Class WorkflowTemplateChangeTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WorkflowTemplateChange_Query query()
 * @method static EO_WorkflowTemplateChange_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_WorkflowTemplateChange_Result getById($id)
 * @method static EO_WorkflowTemplateChange_Result getList(array $parameters = [])
 * @method static EO_WorkflowTemplateChange_Entity getEntity()
 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange_Collection createCollection()
 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateChange_Collection wakeUpCollection($rows)
 */
class WorkflowTemplateChangeTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_bp_workflow_template_change';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new ORM\Fields\IntegerField('TEMPLATE_ID'))
				->configureRequired()
			,
			(new ORM\Fields\IntegerField('EVENT_TYPE'))
				->configureRequired()
			,
			(new ORM\Fields\IntegerField('USER_ID'))
				->configureNullable()
			,
			(new ORM\Fields\DatetimeField('CREATED'))
				->configureRequired()
			,
			(new ORM\Fields\IntegerField('VERSION_NUMBER'))
				->configureNullable()
			,
			(new ORM\Fields\IntegerField('PUBLICATION_TYPE'))
				->configureNullable()
			,
			(new ORM\Fields\ArrayField('SNAPSHOT'))
				->configureNullable()
				->configureSerializeCallback([Entity\WorkflowTemplateTable::class, 'toSerializedForm'])
				->configureUnserializeCallback([Entity\WorkflowTemplateTable::class, 'getFromSerializedForm'])
			,
			(new ORM\Fields\DatetimeField('TEMPLATE_MODIFIED'))
				->configureNullable()
			,
			new ORM\Fields\Relations\Reference(
				'TEMPLATE',
				Entity\WorkflowTemplateTable::class,
				ORM\Query\Join::on('this.TEMPLATE_ID', 'ref.ID')
			),
		];
	}
}

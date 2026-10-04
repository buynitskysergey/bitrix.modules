<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Model\Pilot;

use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\ArrayField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Class WorkflowTemplatePilotTable
 *
 * Snapshot of the executable template fields published to a pilot audience: one row per template.
 * The single-version invariant is held by the unique index on TEMPLATE_ID, so two concurrent
 * publications collide in the database instead of on state read beforehand.
 *
 * MODULE_ID, ENTITY and DOCUMENT_TYPE are denormalized copies of the template columns: the
 * visibility filter needs the document type without joining the template.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WorkflowTemplatePilot_Query query()
 * @method static EO_WorkflowTemplatePilot_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_WorkflowTemplatePilot_Result getById($id)
 * @method static EO_WorkflowTemplatePilot_Result getList(array $parameters = [])
 * @method static EO_WorkflowTemplatePilot_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilot_Collection wakeUpCollection($rows)
 */
final class WorkflowTemplatePilotTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_bp_workflow_template_pilot';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('TEMPLATE_ID'))
				->configureRequired()
			,
			(new StringField('MODULE_ID'))
				->configureRequired()
				->configureSize(32)
			,
			(new StringField('ENTITY'))
				->configureRequired()
				->configureSize(64)
			,
			(new StringField('DOCUMENT_TYPE'))
				->configureRequired()
				->configureSize(128)
			,
			(new ArrayField('TEMPLATE_DATA'))
				->configureRequired()
				->configureSerializeCallback([WorkflowTemplateTable::class, 'toSerializedForm'])
				->configureUnserializeCallback([WorkflowTemplateTable::class, 'getFromSerializedForm'])
			,
			(new StringField('REVISION'))
				->configureRequired()
				->configureSize(64)
			,
			(new IntegerField('CREATED_BY'))
				->configureRequired()
			,
			(new DatetimeField('CREATED'))
				->configureRequired()
			,
		];
	}
}

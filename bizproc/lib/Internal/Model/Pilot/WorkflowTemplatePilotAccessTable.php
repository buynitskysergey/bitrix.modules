<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Model\Pilot;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Class WorkflowTemplatePilotAccessTable
 *
 * Audience of a pilot version: one access code per row. The audience belongs to the version, not to
 * the template, hence PILOT_ID. Department nesting is encoded by the code prefix itself
 * (D{id} - the department alone, DR{id} - with the nested ones), so there is no separate flag.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WorkflowTemplatePilotAccess_Query query()
 * @method static EO_WorkflowTemplatePilotAccess_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_WorkflowTemplatePilotAccess_Result getById($id)
 * @method static EO_WorkflowTemplatePilotAccess_Result getList(array $parameters = [])
 * @method static EO_WorkflowTemplatePilotAccess_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Model\Pilot\EO_WorkflowTemplatePilotAccess_Collection wakeUpCollection($rows)
 */
final class WorkflowTemplatePilotAccessTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_bp_workflow_template_pilot_access';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('PILOT_ID'))
				->configureRequired()
			,
			(new StringField('ACCESS_CODE'))
				->configureRequired()
				->configureSize(100)
			,
		];
	}
}

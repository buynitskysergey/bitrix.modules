<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Trigger;

use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable;

class TriggerRepository
{
	public function hasStartTrigger(
		string $triggerType,
		string $moduleId,
		string $entity,
		string $documentType
	): bool
	{
		return WorkflowTemplateTriggerTable::query()
			->setSelect(['TEMPLATE_ID'])
			->where('TRIGGER_TYPE', $triggerType)
			->where('MODULE_ID', $moduleId)
			->where('ENTITY', $entity)
			->where('DOCUMENT_TYPE', $documentType)
			->setLimit(1)
			->fetch() !== false
		;
	}
}

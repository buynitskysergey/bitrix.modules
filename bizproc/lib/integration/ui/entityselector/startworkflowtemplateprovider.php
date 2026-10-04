<?php

namespace Bitrix\Bizproc\Integration\UI\EntitySelector;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection;
use Bitrix\Bizproc\WorkflowTemplateTable;
use Bitrix\Main\Type\Collection;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;

class StartWorkflowTemplateProvider extends TemplateProvider
{
	protected const ENTITY_ID = 'bizproc-start-workflow-template';

	protected function filterTemplates(EO_WorkflowTemplate_Collection $templates): EO_WorkflowTemplate_Collection
	{
		$nodeDocumentTypes = [];
		foreach ($templates as $template)
		{
			if ($template->getType() === WorkflowTemplateType::Nodes->value)
			{
				$nodeDocumentTypes[(int)$template->getId()] = $template->getDocumentComplexType();
			}
		}

		$availableNodeTemplateIds = Container::instance()
			->getManualStartTemplateAvailabilityService()
			->getAvailableTemplateIds($nodeDocumentTypes)
		;
		Collection::normalizeArrayValuesByInt($availableNodeTemplateIds, false);
		$availableNodeTemplateIdSet = array_fill_keys($availableNodeTemplateIds, true);
		foreach ($nodeDocumentTypes as $templateId => $documentType)
		{
			if (!isset($availableNodeTemplateIdSet[$templateId]))
			{
				$templates->removeByPrimary($templateId);
			}
		}

		return $templates;
	}

	protected function getComplexDocumentTypes(string $moduleId = ''): array
	{
		if ($this->complexDocumentTypesCache === null)
		{
			$complexDocumentTypes = [];
			$legacyTemplates = WorkflowTemplateTable::query()
				->setDistinct()
				->setSelect(['MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'])
				->where($this->getDefaultTemplateFilter())
				->where(
					Query::filter()
						->logic(ConditionTree::LOGIC_OR)
						->where('TYPE', '!=', WorkflowTemplateType::Nodes->value)
						->whereNull('TYPE'),
				)
				->exec()
			;
			while ($template = $legacyTemplates->fetch())
			{
				$documentType = [$template['MODULE_ID'], $template['ENTITY'], $template['DOCUMENT_TYPE']];
				$complexDocumentTypes[implode('|', $documentType)] = $documentType;
			}

			$nodeDocumentTypes = [];
			$nodeTemplates = WorkflowTemplateTable::query()
				->setSelect(['ID', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'])
				->where($this->getDefaultTemplateFilter())
				->where('TYPE', WorkflowTemplateType::Nodes->value)
				->exec()
			;
			while ($template = $nodeTemplates->fetch())
			{
				$nodeDocumentTypes[(int)$template['ID']] = [
					$template['MODULE_ID'],
					$template['ENTITY'],
					$template['DOCUMENT_TYPE'],
				];
			}

			$availableNodeTemplateIds = Container::instance()
				->getManualStartTemplateAvailabilityService()
				->getAvailableTemplateIds($nodeDocumentTypes)
			;
			foreach ($availableNodeTemplateIds as $templateId)
			{
				$documentType = $nodeDocumentTypes[(int)$templateId] ?? null;
				if ($documentType === null)
				{
					continue;
				}

				$complexDocumentTypes[implode('|', $documentType)] = $documentType;
			}

			$this->complexDocumentTypesCache = array_values($complexDocumentTypes);
		}

		if (!$moduleId)
		{
			return $this->complexDocumentTypesCache;
		}

		return array_filter(
			$this->complexDocumentTypesCache,
			static fn(array $documentType): bool => $documentType[0] === $moduleId,
		);
	}
}

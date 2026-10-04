<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\WorkflowTemplate;

use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable;

final class ManualStartTemplateAvailabilityService
{
	private const TEMPLATE_IDS_BATCH_SIZE = 300;

	private array $availabilityCache = [];

	/**
	 * @param array<int, array{0: string, 1: string, 2: string}> $templateDocumentTypes
	 *
	 * @return int[]
	 */
	public function getAvailableTemplateIds(array $templateDocumentTypes): array
	{
		$templateDocumentTypes = $this->normalizeTemplateDocumentTypes($templateDocumentTypes);
		if (!$templateDocumentTypes)
		{
			return [];
		}

		$unknownTemplateIds = [];
		foreach ($templateDocumentTypes as $templateId => $documentType)
		{
			if (!array_key_exists($this->makeCacheKey($templateId, $documentType), $this->availabilityCache))
			{
				$unknownTemplateIds[] = $templateId;
			}
		}

		if ($unknownTemplateIds)
		{
			$this->loadAvailability($unknownTemplateIds, $templateDocumentTypes);
		}

		$result = [];
		foreach ($templateDocumentTypes as $templateId => $documentType)
		{
			if ($this->availabilityCache[$this->makeCacheKey($templateId, $documentType)] ?? false)
			{
				$result[] = $templateId;
			}
		}

		return $result;
	}

	private function normalizeTemplateDocumentTypes(array $templateDocumentTypes): array
	{
		$result = [];
		foreach ($templateDocumentTypes as $templateId => $documentType)
		{
			$templateId = (int)$templateId;
			if ($templateId > 0 && count($documentType) === 3)
			{
				$result[$templateId] = array_values($documentType);
			}
		}

		return $result;
	}

	private function loadAvailability(array $templateIds, array $templateDocumentTypes): void
	{
		foreach ($templateIds as $templateId)
		{
			$documentType = $templateDocumentTypes[$templateId];
			$this->availabilityCache[$this->makeCacheKey($templateId, $documentType)] = false;
		}

		foreach (array_chunk($templateIds, self::TEMPLATE_IDS_BATCH_SIZE) as $templateIdsChunk)
		{
			$iterator = WorkflowTemplateTriggerTable::query()
				->setSelect(['TEMPLATE_ID', 'TRIGGER_TYPE', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'])
				->whereIn('TEMPLATE_ID', $templateIdsChunk)
				->exec()
			;

			while ($row = $iterator->fetch())
			{
				$templateId = (int)$row['TEMPLATE_ID'];
				$documentType = [$row['MODULE_ID'], $row['ENTITY'], $row['DOCUMENT_TYPE']];
				if (
					($templateDocumentTypes[$templateId] ?? null) === $documentType
					&& $this->isManualStartTrigger((string)$row['TRIGGER_TYPE'])
				)
				{
					$this->availabilityCache[$this->makeCacheKey($templateId, $documentType)] = true;
				}
			}
		}
	}

	private function isManualStartTrigger(string $triggerType): bool
	{
		\CBPRuntime::getRuntime()->includeActivityFile('ManualStartTrigger');
		\CBPRuntime::getRuntime()->includeActivityFile($triggerType);

		$className = 'CBP' . $triggerType;

		return class_exists($className) && is_a($className, \CBPManualStartTrigger::class, true);
	}

	private function makeCacheKey(int $templateId, array $documentType): string
	{
		return $templateId . ':' . implode('|', $documentType);
	}
}

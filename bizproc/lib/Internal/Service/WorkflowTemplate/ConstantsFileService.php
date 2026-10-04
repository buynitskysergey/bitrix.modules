<?php

namespace Bitrix\Bizproc\Internal\Service\WorkflowTemplate;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\FileRepository;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateChangeTable;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Result;

class ConstantsFileService
{
	public function __construct(
		private readonly FileRepository $fileRepository,
	) {}

	/**
	 * @param array $constants
	 *
	 * @return list<int>
	 */
	public function getFileIdsFromConstants(array $constants): array
	{

		$fileIds = [];
		foreach ($constants as $constant)
		{
			if (($constant['Type'] ?? null) !== FieldType::FILE)
			{
				continue;
			}

			$value = $constant['Default'] ?? null;
			if (is_numeric($value) && $value > 0)
			{
				$fileIds[] = (int)$value;
			}
			elseif (is_array($value))
			{
				$value = array_filter($value, static fn($item) => is_numeric($item) && $item > 0);
				$value = array_map(static fn($item) => (int)$item, $value);
				$fileIds = array_merge($fileIds, $value);
			}
		}

		return array_unique($fileIds);
	}

	public function getFileIdsByTemplateId(int $templateId): array
	{
		$constants = (array)\CBPWorkflowTemplateLoader::getTemplateConstants($templateId);

		return $this->getFileIdsFromConstants($constants);
	}

	public function add(int $templateId, array $constants): Result
	{
		$fileIds = $this->getFileIdsFromConstants($constants);

		return $this->fileRepository->add($templateId, $fileIds);
	}

	public function update(int $templateId, array $constants): Result
	{
		$fileIds = array_merge(
			$this->getFileIdsFromConstants($constants),
			$this->getFileIdsKeptByHistory($templateId),
		);

		return $this->fileRepository->syncByTemplateId($templateId, array_values(array_unique($fileIds)));
	}

	/**
	 * A file dropped from the live template is still the body of every published version made with it,
	 * so it is released only when the last of those versions leaves the journal.
	 *
	 * @return list<int>
	 */
	private function getFileIdsKeptByHistory(int $templateId): array
	{
		try
		{
			$rows = WorkflowTemplateChangeTable::query()
				->setSelect(['SNAPSHOT'])
				->where('TEMPLATE_ID', $templateId)
				->whereNotNull('SNAPSHOT')
				->fetchAll()
			;
		}
		catch (SqlQueryException)
		{
			return [];
		}

		$fileIds = [];
		foreach ($rows as $row)
		{
			$constants = $row['SNAPSHOT']['CONSTANTS'] ?? null;
			if (is_array($constants))
			{
				$fileIds = array_merge($fileIds, $this->getFileIdsFromConstants($constants));
			}
		}

		return $fileIds;
	}

	public function delete(int $templateId): Result
	{
		return $this->fileRepository->syncByTemplateId($templateId);
	}
}
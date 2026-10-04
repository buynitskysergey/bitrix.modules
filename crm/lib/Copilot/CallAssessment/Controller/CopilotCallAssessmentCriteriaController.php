<?php

namespace Bitrix\Crm\Copilot\CallAssessment\Controller;

use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessmentCriteriaTable;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Result;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\DeleteResult;
use Bitrix\Main\ORM\Data\UpdateResult;

final class CopilotCallAssessmentCriteriaController
{
	use Singleton;

	public function add(array $data): AddResult
	{
		return CopilotCallAssessmentCriteriaTable::add($data);
	}

	public function update(int $id, array $data): UpdateResult
	{
		return CopilotCallAssessmentCriteriaTable::update($id, $data);
	}

	public function deleteById(int $id): ?DeleteResult
	{
		if ($id <= 0)
		{
			return null;
		}

		return CopilotCallAssessmentCriteriaTable::delete($id);
	}

	public function deleteByAssessmentId(int $assessmentId): ?Result
	{
		if ($assessmentId <= 0)
		{
			return null;
		}

		$sqlHelper = Application::getConnection()->getSqlHelper();

		$sql =
			'DELETE FROM ' . CopilotCallAssessmentCriteriaTable::getTableName()
			. ' WHERE ASSESSMENT_ID = ' . $sqlHelper->convertToDbInteger($assessmentId)
		;

		return Application::getConnection()->query($sql);
	}

	public function getById(int $id): ?array
	{
		if ($id <= 0)
		{
			return null;
		}

		return CopilotCallAssessmentCriteriaTable::getByPrimary($id)->fetch() ?: null;
	}

	public function getList(array $params = []): array
	{
		$select = $params['select'] ?? ['*'];
		$filter = $params['filter'] ?? [];
		$order = $params['order'] ?? [
			'SORT' => 'ASC',
			'ID' => 'ASC',
		];
		$offset = $params['offset'] ?? 0;
		$limit = $params['limit'] ?? 0;

		$query = CopilotCallAssessmentCriteriaTable::query()
			->setSelect($select)
			->setFilter($filter)
			->setOrder($order)
		;

		if ($offset > 0)
		{
			$query->setOffset($offset);
		}

		if ($limit > 0)
		{
			$query->setLimit($limit);
		}

		return $query->exec()->fetchAll();
	}
}

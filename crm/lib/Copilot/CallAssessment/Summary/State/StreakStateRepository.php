<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary\State;

use Bitrix\Crm\Copilot\CallAssessment\Summary\Entity\SummaryStateTable;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Situation;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;

final class StreakStateRepository
{
	public function getLastNotifiedAssessmentId(int $managerId, Situation $situation): int
	{
		if ($managerId <= 0)
		{
			return 0;
		}

		$row = SummaryStateTable::query()
			->setSelect(['LAST_ANCHOR_ID'])
			->where('MANAGER_ID', $managerId)
			->where('SITUATION_TYPE', $situation->value)
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? (int)($row['LAST_ANCHOR_ID'] ?? 0) : 0;
	}

	public function setLastNotifiedAssessmentId(int $managerId, Situation $situation, int $assessmentId): void
	{
		if ($managerId <= 0)
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$now = new DateTime();

		$sql = $helper->prepareMerge(
			SummaryStateTable::getTableName(),
			['MANAGER_ID', 'SITUATION_TYPE'],
			[
				'MANAGER_ID' => $managerId,
				'SITUATION_TYPE' => $situation->value,
				'LAST_ANCHOR_ID' => $assessmentId,
				'NOTIFIED_AT' => $now,
			],
			[
				'LAST_ANCHOR_ID' => $assessmentId,
				'NOTIFIED_AT' => $now,
			],
		);

		$statement = is_array($sql) ? current($sql) : $sql;
		if (is_string($statement) && $statement !== '')
		{
			$connection->queryExecute($statement);
			SummaryStateTable::cleanCache();
		}
	}
}

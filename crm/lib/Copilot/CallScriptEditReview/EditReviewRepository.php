<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallScriptEditReview;

use Bitrix\Crm\Copilot\CallScriptEditReview\Entity\CallScriptEditReviewTable;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Type\DateTime;

final class EditReviewRepository
{
	use Singleton;

	public function getJobId(int $assessmentId): ?int
	{
		if ($assessmentId <= 0)
		{
			return null;
		}

		$row = CallScriptEditReviewTable::query()
			->setSelect(['JOB_ID'])
			->setFilter(['=ASSESSMENT_ID' => $assessmentId])
			->setLimit(1)
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		return (int)$row['JOB_ID'];
	}

	public function upsert(int $assessmentId, int $jobId): void
	{
		if ($assessmentId <= 0 || $jobId <= 0)
		{
			return;
		}

		$connection = Application::getConnection();
		$now = new DateTime();

		$queries = $connection->getSqlHelper()->prepareMerge(
			CallScriptEditReviewTable::getTableName(),
			['ASSESSMENT_ID'],
			[
				'ASSESSMENT_ID' => $assessmentId,
				'JOB_ID' => $jobId,
				'CREATED_AT' => $now,
			],
			[
				'JOB_ID' => $jobId,
				'CREATED_AT' => $now,
			],
		);

		foreach ($queries as $query)
		{
			$connection->queryExecute($query);
		}
	}

	public function clearIfMatches(int $assessmentId, int $expectedJobId): bool
	{
		if ($assessmentId <= 0 || $expectedJobId <= 0)
		{
			return false;
		}

		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$connection->queryExecute(sprintf(
			'DELETE FROM %s WHERE ASSESSMENT_ID = %d AND JOB_ID = %d',
			$sqlHelper->quote(CallScriptEditReviewTable::getTableName()),
			$assessmentId,
			$expectedJobId,
		));

		return $connection->getAffectedRowsCount() > 0;
	}

	public function clear(int $assessmentId): bool
	{
		if ($assessmentId <= 0)
		{
			return false;
		}

		$connection = Application::getConnection();
		$sql = (new SqlExpression(
			'DELETE FROM ?# WHERE ?# = ?i',
			CallScriptEditReviewTable::getTableName(),
			'ASSESSMENT_ID',
			$assessmentId,
		))->compile();

		$connection->queryExecute($sql);

		return $connection->getAffectedRowsCount() > 0;
	}
}

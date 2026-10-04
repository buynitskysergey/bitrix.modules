<?php

namespace Bitrix\Bizproc\Internal\Repository\WorkflowStateRepository;

use Bitrix\Bizproc\Internal\Entity\WorkflowState\WorkflowStateCollection;
use Bitrix\Bizproc\Internal\Repository\Mapper\WorkflowStateMapper;
use Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable;
use Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

class WorkflowStateRepository
{
	public function __construct(private readonly WorkflowStateMapper $mapper)
	{
	}

	public function getStaleWorkflowsWithoutTasks(
		array $select,
		Date $beforeDate,
		int $limit,
		?Date $afterDate = null,
	): WorkflowStateCollection
	{
		$query
			= WorkflowStateTable::query()
				->setSelect($select)
				->whereNull('INSTANCE.ID')
				->whereNull('TASKS.ID')
				->whereNull('TASKS_ARCHIVE.ID')
				->where('STARTED', '<', $beforeDate)
				->setLimit($limit)
				->setOrder(['STARTED' => 'ASC'])
		;

		if ($afterDate)
		{
			$query->where('STARTED', '>=', $afterDate);
		}

		$ormWorkflowStates = $query->fetchCollection();

		return $this->mapper->convertCollectionFromOrm($ormWorkflowStates);
	}

	public function getStuckWorkflows(
		array $select,
		DateTime $startPeriod,
		DateTime $endPeriod,
		Date $beforeDate,
		int $limit,
	): WorkflowStateCollection
	{
		$query
			= WorkflowStateTable::query()
				->setSelect($select)
				->where('STARTED', '>=', $startPeriod)
				->where('STARTED', '<', $endPeriod)
				->where('INSTANCE.OWNED_UNTIL', '<', $beforeDate)
				->setLimit($limit)
		;
		$ormWorkflowStates = $query->fetchCollection();

		return $this->mapper->convertCollectionFromOrm($ormWorkflowStates);
	}

	/**
	 * Candidates are taken from a moving MODIFIED window: [$modifiedFrom, $modifiedBefore). Without the
	 * lower bound the selection matches every legitimately waiting workflow of the portal on every daily
	 * pass, and each match costs a serialized activity tree read in the detector.
	 *
	 * @return list<array{ID: string, MODIFIED: DateTime}>
	 */
	public function getSuspendedPauseCandidatesPage(
		DateTime $modifiedFrom,
		DateTime $modifiedBefore,
		int $limit,
		?int $afterModifiedTimestamp,
		?string $afterWorkflowId,
	): array
	{
		return $this
			->createCandidateQuery($modifiedFrom, $modifiedBefore, $limit, $afterModifiedTimestamp, $afterWorkflowId)
			->where('STATUS', \CBPWorkflowStatus::Suspended)
			->fetchAll()
		;
	}

	/**
	 * @return list<array{ID: string, MODIFIED: DateTime}>
	 */
	public function getStaleLockedCandidatesPage(
		DateTime $modifiedFrom,
		DateTime $modifiedBefore,
		int $limit,
		?int $afterModifiedTimestamp,
		?string $afterWorkflowId,
	): array
	{
		$lockThreshold = DateTime::createFromTimestamp(
			time() - WorkflowInstanceTable::LOCKED_TIME_INTERVAL,
		);

		return $this
			->createCandidateQuery($modifiedFrom, $modifiedBefore, $limit, $afterModifiedTimestamp, $afterWorkflowId)
			->whereNotNull('OWNED_UNTIL')
			->where('OWNED_UNTIL', '<', $lockThreshold)
			->fetchAll()
		;
	}

	private function createCandidateQuery(
		DateTime $modifiedFrom,
		DateTime $modifiedBefore,
		int $limit,
		?int $afterModifiedTimestamp,
		?string $afterWorkflowId,
	): Query
	{
		$query = WorkflowInstanceTable::query()
			->setSelect(['ID', 'MODIFIED'])
			->where('MODIFIED', '>=', $modifiedFrom)
			->where('MODIFIED', '<', $modifiedBefore)
			->setOrder(['MODIFIED' => 'ASC', 'ID' => 'ASC'])
			->setLimit($limit)
		;

		if ($afterModifiedTimestamp === null || $afterWorkflowId === null || $afterWorkflowId === '')
		{
			return $query;
		}

		$afterModified = DateTime::createFromTimestamp($afterModifiedTimestamp);

		return $query->where(
			Query::filter()
				->logic('or')
				->where('MODIFIED', '>', $afterModified)
				->where(
					Query::filter()
						->where('MODIFIED', $afterModified)
						->where('ID', '>', $afterWorkflowId)
				)
		);
	}
}

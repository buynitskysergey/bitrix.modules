<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\WorkflowState\ClearStuckPauseWorkflowCommand;

use Bitrix\Bizproc\Activity\Enum\ResumeWorkflowQueue;
use Bitrix\Bizproc\Internal\Repository\WorkflowStateRepository\WorkflowStateRepository;
use Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\WorkflowResumeMessageTable;
use Bitrix\Bizproc\Internal\Service\WorkflowState\StuckPauseCandidateRegistry;
use Bitrix\Bizproc\Internal\Service\WorkflowState\StuckPauseWorkflowDetector;
use Bitrix\Bizproc\SchedulerEventTable;
use Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Type\DateTime;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ClearStuckPauseWorkflowCommandHandler
{
	private const SWEEP_WINDOW_START_OPTION = 'clear_stuck_pause_sweep_from';
	private const SWEPT_UNTIL_OPTION = 'clear_stuck_pause_swept_until';
	private const LOGGER_ID = 'bizproc.workflow.stuckPause';
	private const WORKFLOW_LOCK_PREFIX = 'bizproc_';

	private ?LoggerInterface $logger = null;

	public function __construct(
		private readonly WorkflowStateRepository $workflowStateRepository,
		private readonly StuckPauseWorkflowDetector $stuckPauseWorkflowDetector,
	)
	{
	}

	public function __invoke(ClearStuckPauseWorkflowCommand $command): ClearStuckPauseWorkflowResult
	{
		$graceDays = max(1, (int)Option::get('bizproc', 'clear_stuck_pause_grace_days', StuckPauseWorkflowDetector::DEFAULT_GRACE_DAYS));
		$modifiedBefore = DateTime::createFromTimestamp(time() - $graceDays * 86400);
		$modifiedFrom = DateTime::createFromTimestamp($this->getSweepWindowStart($modifiedBefore));

		$candidateRegistry = new StuckPauseCandidateRegistry();

		$cursor = $command->cursor ?? StuckPauseSweepCursor::start();
		$budget = $command->limit;
		while ($budget > 0 && $cursor !== null)
		{
			$rows = $this->fetchCandidatesPage($cursor, $modifiedFrom, $modifiedBefore, $budget);
			foreach ($rows as $row)
			{
				$this->deleteIfConfirmedStuck((string)$row['ID'], $candidateRegistry);
				$cursor = $cursor->withPosition($row['MODIFIED']->getTimestamp(), (string)$row['ID']);
			}

			if (count($rows) < $budget)
			{
				$cursor = $cursor->nextStage();
			}

			$budget -= count($rows);
		}

		$candidateRegistry->save();

		if ($cursor === null)
		{
			$this->closeSweepWindow($modifiedBefore);
		}

		return new ClearStuckPauseWorkflowResult($cursor);
	}

	/**
	 * @return list<array{ID: string, MODIFIED: DateTime}>
	 */
	private function fetchCandidatesPage(
		StuckPauseSweepCursor $cursor,
		DateTime $modifiedFrom,
		DateTime $modifiedBefore,
		int $limit,
	): array
	{
		return match ($cursor->stage)
		{
			StuckPauseSweepCursor::STAGE_SUSPENDED => $this->workflowStateRepository->getSuspendedPauseCandidatesPage(
				$modifiedFrom,
				$modifiedBefore,
				$limit,
				$cursor->afterModifiedTimestamp,
				$cursor->afterWorkflowId,
			),
			StuckPauseSweepCursor::STAGE_STALE_LOCKED => $this->workflowStateRepository->getStaleLockedCandidatesPage(
				$modifiedFrom,
				$modifiedBefore,
				$limit,
				$cursor->afterModifiedTimestamp,
				$cursor->afterWorkflowId,
			),
		};
	}

	private function deleteIfConfirmedStuck(string $workflowId, StuckPauseCandidateRegistry $candidateRegistry): void
	{
		if (!$this->stuckPauseWorkflowDetector->isStuck($workflowId))
		{
			$candidateRegistry->forget($workflowId);

			return;
		}

		if (!$candidateRegistry->isConfirmed($workflowId))
		{
			$candidateRegistry->remember($workflowId);

			return;
		}

		// A workflow that revived is forgotten by the next pass: its outer check above sees it alive.
		if ($this->killStillStuckUnderLock($workflowId))
		{
			$candidateRegistry->forget($workflowId);
		}
	}

	/**
	 * The runtime takes ownership of an instance under the same named lock (CBPWorkflowPersister), so a
	 * verdict reached outside of it may be stale by the moment of the removal: the instance is checked
	 * again under the lock. A busy lock means the instance is executing right now - alive by definition.
	 */
	private function killStillStuckUnderLock(string $workflowId): bool
	{
		$connection = Application::getConnection();
		if (!$connection->lock(self::WORKFLOW_LOCK_PREFIX . $workflowId))
		{
			return false;
		}

		try
		{
			if (!$this->stuckPauseWorkflowDetector->isStuck($workflowId))
			{
				return false;
			}

			$this->logRemoval($workflowId);

			// Legacy b_agent wake-up subscriptions (CBPSchedulerService::OnAgent/repeatEvent) are not
			// deleted here: on next run they hit INSTANCE_NOT_FOUND and self-remove (empty eval result).
			\CBPDocument::killWorkflow($workflowId, false);
			SchedulerEventTable::deleteByWorkflow($workflowId);
			$this->deletePendingResumeMessages($workflowId);

			return true;
		}
		finally
		{
			$connection->unlock(self::WORKFLOW_LOCK_PREFIX . $workflowId);
		}
	}

	/**
	 * A candidate has to stay selectable long enough to be sighted by one pass and confirmed by a later one,
	 * so the window is never narrower than the lifetime of a sighting. Raising the grace period moves the
	 * ceiling of the window back, below the floor the previous pass left behind, and would close it.
	 */
	private function getSweepWindowStart(DateTime $modifiedBefore): int
	{
		$sweptFrom = max(0, (int)Option::get('bizproc', self::SWEEP_WINDOW_START_OPTION, '0'));

		return min($sweptFrom, $modifiedBefore->getTimestamp() - StuckPauseCandidateRegistry::SIGHTING_TTL);
	}

	/**
	 * The next pass starts where the pass before the finished one ended (0 for the very first one), so every
	 * MODIFIED value stays inside the window for several consecutive passes. Confirmation needs two sightings
	 * of the same candidate, and a window advanced right up to the finished ceiling would drop candidates out
	 * of the selection before the second one - starting with the whole backlog of already stuck workflows.
	 */
	private function closeSweepWindow(DateTime $sweptUntil): void
	{
		$previousSweptUntil = (int)Option::get('bizproc', self::SWEPT_UNTIL_OPTION, '0');

		Option::set(
			'bizproc',
			self::SWEEP_WINDOW_START_OPTION,
			(string)max(0, $previousSweptUntil - StuckPauseCandidateRegistry::SIGHTING_TTL),
		);
		Option::set('bizproc', self::SWEPT_UNTIL_OPTION, (string)$sweptUntil->getTimestamp());
	}

	private function deletePendingResumeMessages(string $workflowId): void
	{
		WorkflowResumeMessageTable::deleteByFilter([
			'=QUEUE_ID' => ResumeWorkflowQueue::values(),
			'=ITEM_ID' => $workflowId,
		]);
	}

	// Removal takes the instance, the state and the tracking with it: the trail is read while the rows
	// are still there.
	private function logRemoval(string $workflowId): void
	{
		$instance = WorkflowInstanceTable::query()
			->setSelect(['MODULE_ID', 'ENTITY', 'DOCUMENT_ID', 'WORKFLOW_TEMPLATE_ID', 'STATUS', 'MODIFIED'])
			->where('ID', $workflowId)
			->fetch() ?: []
		;

		$this->getLogger()->warning(
			'Bizproc stuck pause sweep removed workflow {workflowId} confirmed stuck on two passes:'
			. ' template {templateId}, document {moduleId}/{entity}/{documentId},'
			. ' status {status}, modified {modified}',
			[
				'workflowId' => $workflowId,
				'templateId' => (string)($instance['WORKFLOW_TEMPLATE_ID'] ?? ''),
				'moduleId' => (string)($instance['MODULE_ID'] ?? ''),
				'entity' => (string)($instance['ENTITY'] ?? ''),
				'documentId' => (string)($instance['DOCUMENT_ID'] ?? ''),
				'status' => (string)($instance['STATUS'] ?? ''),
				'modified' => (string)($instance['MODIFIED'] ?? ''),
			],
		);
	}

	private function getLogger(): LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}
}

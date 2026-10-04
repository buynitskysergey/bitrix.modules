<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Cleanup;

use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\ValueObject\ManagedAgentResourcePayload;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstance;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResource;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType;
use Bitrix\Bizproc\Internal\Repository\AiAgent\ManagedAgentInstanceRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\AiAgent\ManagedAgentResourceRepositoryInterface;
use Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable;

/**
 * Cleanup participant of the workflows owned by a managed system AI agent instance.
 *
 * Workflows are cleaned up before the bots and the storage scope they use, because an action performed
 * synchronously inside a workflow is covered by the registration of that workflow and by its mandatory
 * termination: once no active instance of the copy is left, no later type can gain new work.
 *
 * A completed workflow is history and not a resource. A registered workflow that is no longer in
 * WorkflowInstanceTable counts as cleaned up without calling killWorkflow(), therefore its workflow_state,
 * its tracking log and its results are kept. This is easy to break by "just deleting the row as well", so it
 * has its own regression test in the phase of the data preservation matrix.
 */
final class WorkflowCleanup implements ManagedResourceCleanupInterface
{
	/**
	 * Own deadline of this participant: the pass as a whole lasts five seconds and is shared by five types.
	 */
	public const DEFAULT_DEADLINE_SECONDS = 1.0;

	/**
	 * Live processes one reconciliation reads at a time: a batch of this size never lets the reconciliation of
	 * one type spend the rows the deletion of the very same pass needs, and a portion it did not reach is
	 * continued by the next pass, which meets fewer processes because this one terminated some of them.
	 */
	public const RECONCILE_BATCH_LIMIT = 50;

	public const ERROR_DATA_INVALID = 'AI_AGENT_WORKFLOW_DATA_INVALID';

	public const ERROR_RESOURCE_ID_INVALID = 'AI_AGENT_WORKFLOW_ID_INVALID';

	public const ERROR_OWNER_UNREADABLE = 'AI_AGENT_WORKFLOW_OWNER_UNREADABLE';

	public const ERROR_READ_FAILED = 'AI_AGENT_WORKFLOW_READ_FAILED';

	public const ERROR_WRITE_FAILED = 'AI_AGENT_WORKFLOW_WRITE_FAILED';

	public const ERROR_TERMINATE_FAILED = 'AI_AGENT_WORKFLOW_TERMINATE_FAILED';

	/**
	 * The termination was accepted while the instance is still there: a blocked continuation until the deadline,
	 * a failure after. Both of them charge the retry delay, because the process ends by neither of them.
	 */
	public const REASON_STILL_ACTIVE = 'AI_AGENT_WORKFLOW_STILL_ACTIVE';

	private readonly ?ManagedAgentInstanceRepositoryInterface $instanceRepository;

	private readonly ?ManagedAgentResourceRepositoryInterface $resourceRepository;

	/**
	 * @var array<int, int|null> template id of the copy an instance owns, by instance id, null when it is gone
	 */
	private array $ownedTemplateIds = [];

	public function __construct(
		?ManagedAgentInstanceRepositoryInterface $instanceRepository = null,
		?ManagedAgentResourceRepositoryInterface $resourceRepository = null,
		private readonly float $deadlineSeconds = self::DEFAULT_DEADLINE_SECONDS,
	)
	{
		$this->instanceRepository = $instanceRepository ?? Container::getManagedAgentInstanceRepository();
		$this->resourceRepository = $resourceRepository ?? Container::getManagedAgentResourceRepository();
	}

	public function type(): ManagedAgentResourceType
	{
		return ManagedAgentResourceType::Workflow;
	}

	public function reconcile(
		ManagedAgentInstance $instance,
		ManagedResourceCleanupBudget $budget,
	): ManagedResourceCleanupResult
	{
		if ($budget->isExhausted())
		{
			return ManagedResourceCleanupResult::createPending();
		}

		$budget->startParticipantDeadline($this->type(), $this->deadlineSeconds);

		if ($this->resourceRepository === null)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_WRITE_FAILED);
		}

		$instanceId = (int)$instance->getId();
		if ($instanceId <= 0)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_OWNER_UNREADABLE);
		}

		$templateId = $instance->getTemplateId();
		if ($templateId === null)
		{
			// Without a copy no workflow of the instance can exist; a stale row is confirmed by cleanup().
			return ManagedResourceCleanupResult::createComplete();
		}

		$limit = min($budget->getRemainingRows(), self::RECONCILE_BATCH_LIMIT);

		try
		{
			$workflowIds = self::readActiveWorkflowIds($templateId, $limit);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_READ_FAILED);
		}

		$budget->consumeRows(count($workflowIds));

		// The portion itself may have used up the budget, and then it is adopted by the next pass unread.
		if ($workflowIds !== [] && $budget->isExhausted())
		{
			return ManagedResourceCleanupResult::createPending();
		}

		try
		{
			$registered = array_flip(
				$this->resourceRepository->findRegisteredResourceIds($instanceId, $this->type(), $workflowIds),
			);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_READ_FAILED);
		}

		foreach ($workflowIds as $workflowId)
		{
			if ($budget->isExhausted())
			{
				return ManagedResourceCleanupResult::createPending();
			}

			try
			{
				$this->adopt($instanceId, $workflowId, $registered, $budget);
			}
			catch (\Throwable)
			{
				return ManagedResourceCleanupResult::createFailed(self::ERROR_WRITE_FAILED);
			}
		}

		return count($workflowIds) >= $limit
			? ManagedResourceCleanupResult::createPending()
			: ManagedResourceCleanupResult::createComplete()
		;
	}

	public function cleanup(
		ManagedAgentResource $resource,
		ManagedResourceCleanupBudget $budget,
	): ManagedResourceCleanupResult
	{
		if ($budget->isExhausted())
		{
			return ManagedResourceCleanupResult::createPending();
		}

		$budget->startParticipantDeadline($this->type(), $this->deadlineSeconds);

		if (!ManagedAgentResourcePayload::matchesSchema($resource->getData(), $this->type()))
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_DATA_INVALID);
		}

		$workflowId = $resource->getResourceId();
		if ($workflowId === '')
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_RESOURCE_ID_INVALID);
		}

		if ($this->instanceRepository === null)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_OWNER_UNREADABLE);
		}

		try
		{
			$active = self::readActiveWorkflow($workflowId);
			$budget->consumeRows(1);

			if ($active === null)
			{
				// The workflow has finished: it is cleaned up as is, and its history is not deleted.
				return ManagedResourceCleanupResult::createComplete();
			}

			$ownedTemplateId = $this->resolveOwnedTemplateId($resource->getInstanceId(), $budget);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_READ_FAILED);
		}

		if ($ownedTemplateId === null || (int)$active['WORKFLOW_TEMPLATE_ID'] !== $ownedTemplateId)
		{
			// Ownership of this row is not confirmed, so the workflow of another template stays untouched.
			return ManagedResourceCleanupResult::createComplete();
		}

		return $this->terminateWorkflow($workflowId, $active, $budget);
	}

	/**
	 * Template id of the copy the instance owns, or null while the instance is gone.
	 *
	 * One portion hands this participant up to {@see self::RECONCILE_BATCH_LIMIT} rows of the same instance, and
	 * the copy of an instance does not change while that instance is being removed, therefore the row is read
	 * once and the answer serves the whole portion instead of costing one identical query per resource. An
	 * absent instance is remembered as well, so that it does not keep the query per resource of its own.
	 *
	 * The budget is charged for the read alone: a remembered answer performed no query, and charging it would
	 * spend rows the deletion of the very same pass needs to converge.
	 */
	private function resolveOwnedTemplateId(int $instanceId, ManagedResourceCleanupBudget $budget): ?int
	{
		if (array_key_exists($instanceId, $this->ownedTemplateIds))
		{
			return $this->ownedTemplateIds[$instanceId];
		}

		$templateId = $this->instanceRepository->getById($instanceId)?->getTemplateId();
		$budget->consumeRows(1);

		$this->ownedTemplateIds[$instanceId] = $templateId;

		return $templateId;
	}

	/**
	 * @param array $active row of {@see self::readActiveWorkflow()}, whose document the termination verifies
	 */
	private function terminateWorkflow(
		string $workflowId,
		array $active,
		ManagedResourceCleanupBudget $budget,
	): ManagedResourceCleanupResult
	{
		$documentId = [
			(string)$active['MODULE_ID'],
			(string)$active['ENTITY'],
			(string)$active['DOCUMENT_ID'],
		];

		try
		{
			$errors = \CBPDocument::killWorkflow($workflowId, true, $documentId);
			$budget->consumeRows(1);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_TERMINATE_FAILED);
		}

		if (!empty($errors))
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_TERMINATE_FAILED);
		}

		try
		{
			$stillActive = self::readActiveWorkflow($workflowId) !== null;
			$budget->consumeRows(1);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_READ_FAILED);
		}

		if (!$stillActive)
		{
			return ManagedResourceCleanupResult::createComplete();
		}

		return $budget->isParticipantOverdue()
			? ManagedResourceCleanupResult::createFailed(self::REASON_STILL_ACTIVE)
			: ManagedResourceCleanupResult::createBlocked(self::REASON_STILL_ACTIVE)
		;
	}

	/**
	 * Registers an active workflow of the copy that has no ownership row yet, which closes the window between
	 * the start of a workflow and its registration.
	 *
	 * The rows of the whole portion are read at once, and every workflow of it still costs the one row of the
	 * budget its own lookup used to cost: what the portion changes is the number of queries and not the amount
	 * of work a pass is allowed to do.
	 *
	 * @param array<string, mixed> $registered ids of the portion that are registered already, as keys
	 */
	private function adopt(
		int $instanceId,
		string $resourceId,
		array $registered,
		ManagedResourceCleanupBudget $budget,
	): void
	{
		$budget->consumeRows(1);

		if (isset($registered[$resourceId]))
		{
			return;
		}

		$this->resourceRepository->save(
			new ManagedAgentResource(null, $instanceId, $this->type(), $resourceId, ManagedAgentResourcePayload::build()),
		);
		$budget->consumeRows(1);
	}

	/**
	 * @return list<string> ids of the active workflows of the copy, at most one batch of them, ordered by id
	 */
	private static function readActiveWorkflowIds(int $templateId, int $limit): array
	{
		$rows = WorkflowInstanceTable::query()
			->setSelect(['ID'])
			->where('WORKFLOW_TEMPLATE_ID', $templateId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn (array $row): string => (string)$row['ID'], $rows);
	}

	/**
	 * Row of the active workflow with the document it runs on, or null when no active instance is left.
	 */
	private static function readActiveWorkflow(string $workflowId): ?array
	{
		$row = WorkflowInstanceTable::query()
			->setSelect(['ID', 'MODULE_ID', 'ENTITY', 'DOCUMENT_ID', 'WORKFLOW_TEMPLATE_ID'])
			->where('ID', $workflowId)
			->setLimit(1)
			->fetch()
		;

		return $row === false ? null : $row;
	}
}

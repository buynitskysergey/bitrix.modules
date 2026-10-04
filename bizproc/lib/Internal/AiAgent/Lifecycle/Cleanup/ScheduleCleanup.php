<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Cleanup;

use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\ValueObject\ManagedAgentResourcePayload;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstance;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResource;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType;
use Bitrix\Bizproc\Internal\Model\Trigger\TriggerScheduleTable;
use Bitrix\Bizproc\Internal\Repository\AiAgent\ManagedAgentInstanceRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\AiAgent\ManagedAgentResourceRepositoryInterface;

/**
 * Cleanup participant of the trigger schedules owned by a managed system AI agent instance.
 *
 * Schedules are cleaned up first, because a schedule is the one resource that is able to produce a new
 * workflow start on its own: as long as a row of the managed copy is still due, the next types would keep
 * finding work that was created after their own pass.
 *
 * A schedule is never deleted on the strength of the service row alone. The row of the actual table is read
 * first and its TEMPLATE_ID is compared with the copy of the instance, so a claim that no longer holds drops
 * the service row instead of touching a schedule that belongs to another, possibly manual, template.
 */
final class ScheduleCleanup implements ManagedResourceCleanupInterface
{
	/**
	 * Own deadline of this participant: the pass as a whole lasts five seconds and is shared by five types.
	 */
	public const DEFAULT_DEADLINE_SECONDS = 1.0;

	/**
	 * Live schedules one reconciliation reads at a time: a batch of this size never lets the reconciliation of
	 * one type spend the rows the deletion of the very same pass needs, and a portion it did not reach is
	 * continued by the next pass, which meets fewer schedules because this one deleted some of them.
	 */
	public const RECONCILE_BATCH_LIMIT = 50;

	public const ERROR_DATA_INVALID = 'AI_AGENT_SCHEDULE_DATA_INVALID';

	public const ERROR_RESOURCE_ID_INVALID = 'AI_AGENT_SCHEDULE_ID_INVALID';

	public const ERROR_OWNER_UNREADABLE = 'AI_AGENT_SCHEDULE_OWNER_UNREADABLE';

	public const ERROR_READ_FAILED = 'AI_AGENT_SCHEDULE_READ_FAILED';

	public const ERROR_WRITE_FAILED = 'AI_AGENT_SCHEDULE_WRITE_FAILED';

	public const ERROR_DELETE_FAILED = 'AI_AGENT_SCHEDULE_DELETE_FAILED';

	/**
	 * The deletion was accepted while the row is still there: a blocked continuation until the deadline, a
	 * failure after. Both of them charge the retry delay, because the row disappears from neither of them.
	 */
	public const REASON_STILL_PRESENT = 'AI_AGENT_SCHEDULE_STILL_PRESENT';

	private const SCHEDULE_ID_PATTERN = '/^[1-9][0-9]{0,17}$/D';

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
		return ManagedAgentResourceType::Schedule;
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
			// Without a copy no schedule of the instance can exist; a stale row is confirmed by cleanup().
			return ManagedResourceCleanupResult::createComplete();
		}

		$limit = min($budget->getRemainingRows(), self::RECONCILE_BATCH_LIMIT);

		try
		{
			$scheduleIds = self::readScheduleIds($templateId, $limit);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_READ_FAILED);
		}

		$budget->consumeRows(count($scheduleIds));

		// The portion itself may have used up the budget, and then it is adopted by the next pass unread.
		if ($scheduleIds !== [] && $budget->isExhausted())
		{
			return ManagedResourceCleanupResult::createPending();
		}

		try
		{
			$registered = array_flip(
				$this->resourceRepository->findRegisteredResourceIds($instanceId, $this->type(), $scheduleIds),
			);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_READ_FAILED);
		}

		foreach ($scheduleIds as $scheduleId)
		{
			if ($budget->isExhausted())
			{
				return ManagedResourceCleanupResult::createPending();
			}

			try
			{
				$this->adopt($instanceId, $scheduleId, $registered, $budget);
			}
			catch (\Throwable)
			{
				return ManagedResourceCleanupResult::createFailed(self::ERROR_WRITE_FAILED);
			}
		}

		return count($scheduleIds) >= $limit
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

		$scheduleId = self::parseScheduleId($resource->getResourceId());
		if ($scheduleId === null)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_RESOURCE_ID_INVALID);
		}

		if ($this->instanceRepository === null)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_OWNER_UNREADABLE);
		}

		try
		{
			$actualTemplateId = self::readScheduleTemplateId($scheduleId);
			$budget->consumeRows(1);

			if ($actualTemplateId === null)
			{
				return ManagedResourceCleanupResult::createComplete();
			}

			$ownedTemplateId = $this->resolveOwnedTemplateId($resource->getInstanceId(), $budget);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_READ_FAILED);
		}

		if ($ownedTemplateId === null || $actualTemplateId !== $ownedTemplateId)
		{
			// Ownership of this row is not confirmed, so the schedule of another template stays untouched.
			return ManagedResourceCleanupResult::createComplete();
		}

		return $this->deleteSchedule($scheduleId, $budget);
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

	private function deleteSchedule(int $scheduleId, ManagedResourceCleanupBudget $budget): ManagedResourceCleanupResult
	{
		try
		{
			$deleted = TriggerScheduleTable::delete($scheduleId)->isSuccess();
			$budget->consumeRows(1);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_DELETE_FAILED);
		}

		if (!$deleted)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_DELETE_FAILED);
		}

		try
		{
			$remaining = self::readScheduleTemplateId($scheduleId);
			$budget->consumeRows(1);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::ERROR_READ_FAILED);
		}

		if ($remaining === null)
		{
			return ManagedResourceCleanupResult::createComplete();
		}

		return $budget->isParticipantOverdue()
			? ManagedResourceCleanupResult::createFailed(self::REASON_STILL_PRESENT)
			: ManagedResourceCleanupResult::createBlocked(self::REASON_STILL_PRESENT)
		;
	}

	/**
	 * Registers a live schedule of the copy that has no ownership row yet, which closes the window between the
	 * creation of a schedule and its registration.
	 *
	 * The rows of the whole portion are read at once, and every schedule of it still costs the one row of the
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
	 * @return list<string> ids of the schedules of the copy, at most one batch of them, ordered by id
	 */
	private static function readScheduleIds(int $templateId, int $limit): array
	{
		$rows = TriggerScheduleTable::query()
			->setSelect(['ID'])
			->where('TEMPLATE_ID', $templateId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn (array $row): string => (string)$row['ID'], $rows);
	}

	/**
	 * Template the stored schedule belongs to, or null when the schedule is already gone.
	 */
	private static function readScheduleTemplateId(int $scheduleId): ?int
	{
		$row = TriggerScheduleTable::query()
			->setSelect(['TEMPLATE_ID'])
			->where('ID', $scheduleId)
			->setLimit(1)
			->fetch()
		;

		return $row === false ? null : (int)$row['TEMPLATE_ID'];
	}

	private static function parseScheduleId(string $resourceId): ?int
	{
		return preg_match(self::SCHEDULE_ID_PATTERN, $resourceId) === 1 ? (int)$resourceId : null;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\WorkflowState;

use Bitrix\Bizproc\Activity\Enum\ResumeWorkflowQueue;
use Bitrix\Bizproc\Exception\EmptyWorkflowInstanceException;
use Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\WorkflowResumeMessageTable;
use Bitrix\Bizproc\SchedulerEventTable;
use Bitrix\Bizproc\Service\Entity\TrackingTable;
use Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Messenger\Internals\Storage\Db\Model\MessageStatus;
use Bitrix\Main\Type\DateTime;
use CBPActivity;
use CBPActivityExecutionStatus;
use CBPWorkflowPersister;

class StuckPauseWorkflowDetector
{
	public const DEFAULT_GRACE_DAYS = 7;
	private const TIMEMAN_EVENT_MODULE = 'timeman';

	/** @var array<int, array|null> */
	private array $templateCache = [];

	public function isStuck(string $workflowId): bool
	{
		$instance = WorkflowInstanceTable::query()
			->setSelect(['ID', 'MODIFIED', 'STATUS', 'OWNER_ID', 'OWNED_UNTIL'])
			->where('ID', $workflowId)
			->fetch()
		;

		if (!$instance)
		{
			return false;
		}

		if (!$this->isInstanceStale($instance))
		{
			return false;
		}

		try
		{
			$rootActivity = CBPWorkflowPersister::getPersister()->loadWorkflow($workflowId, true);
		}
		catch (EmptyWorkflowInstanceException)
		{
			return $this->isStuckStalePauseWithoutWorkflow($workflowId, $instance);
		}
		catch (\Throwable)
		{
			return false;
		}

		$pauseActivities = $this->findExecutingPauseActivities($rootActivity);

		if ($pauseActivities === [])
		{
			return false;
		}

		foreach ($pauseActivities as $pauseActivity)
		{
			if ($this->hasValidWakeMechanism($workflowId, $pauseActivity, $instance))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @return StuckPauseActivityInfo[]
	 */
	public function findExecutingPauseActivities(CBPActivity $activity): array
	{
		$result = [];

		foreach ($activity->walkRecursive() as $nested)
		{
			if (
				$nested->executionStatus === CBPActivityExecutionStatus::Executing
				&& in_array($nested->getType(), PauseActivityType::ALL, true)
			)
			{
				$result[] = new StuckPauseActivityInfo($nested->getName(), $nested->getType());
			}
		}

		return $result;
	}

	private function isInstanceStale(array $instance): bool
	{
		$modified = $instance['MODIFIED'] ?? null;
		if (!$modified instanceof DateTime)
		{
			return false;
		}

		if ($modified->getTimestamp() >= $this->getStaleModifiedThreshold())
		{
			return false;
		}

		$ownedUntil = $instance['OWNED_UNTIL'] ?? null;
		if ($ownedUntil instanceof DateTime)
		{
			$lockThreshold = time() - WorkflowInstanceTable::LOCKED_TIME_INTERVAL;

			return $ownedUntil->getTimestamp() < $lockThreshold;
		}

		return (int)($instance['STATUS'] ?? 0) === \CBPWorkflowStatus::Suspended;
	}

	private function hasValidWakeMechanism(
		string $workflowId,
		StuckPauseActivityInfo $pauseActivity,
		array $instance,
	): bool
	{
		if ($this->hasStaleInstanceLock($instance))
		{
			return false;
		}

		if ($this->hasTimemanSubscription($workflowId, $pauseActivity->name))
		{
			return $this->hasValidTimemanWakeMechanism($workflowId, $pauseActivity->name);
		}

		// Check both transports: the workflow may have been paused before a transport switch,
		// so the wake-up may live in either the messenger queue or a b_agent record.
		return $this->hasPendingResumeMessage($workflowId, $pauseActivity->name, $instance)
			|| $this->hasPendingDelayAgent($workflowId, $pauseActivity->name);
	}

	/**
	 * Stale lock: foreign OWNER_ID with an expired OWNED_UNTIL.
	 * Any resume attempt via messenger/agent will keep hitting INSTANCE_LOCKED indefinitely.
	 *
	 * @param array{OWNER_ID?: string|null, OWNED_UNTIL?: DateTime|null} $instance
	 */
	private function hasStaleInstanceLock(array $instance): bool
	{
		$ownerId = $instance['OWNER_ID'] ?? null;
		if ($ownerId === null || $ownerId === '')
		{
			return false;
		}

		$ownedUntil = $instance['OWNED_UNTIL'] ?? null;
		if (!$ownedUntil instanceof DateTime)
		{
			return false;
		}

		$lockThreshold = time() - WorkflowInstanceTable::LOCKED_TIME_INTERVAL;

		return $ownedUntil->getTimestamp() < $lockThreshold;
	}

	private function hasValidTimemanWakeMechanism(string $workflowId, string $activityName): bool
	{
		$deadline = $this->resolvePauseDeadlineFromTracking($workflowId, $activityName);
		if ($deadline === null)
		{
			return true;
		}

		return $deadline >= $this->getWakeScheduleThreshold();
	}

	private function hasTimemanSubscription(string $workflowId, string $activityName): bool
	{
		return SchedulerEventTable::query()
			->where('WORKFLOW_ID', $workflowId)
			->where('HANDLER', $activityName)
			->where('EVENT_MODULE', self::TIMEMAN_EVENT_MODULE)
			->setLimit(1)
			->fetch() !== false
		;
	}

	private function hasPendingDelayAgent(string $workflowId, string $activityName): bool
	{
		$namePrefix = "CBPSchedulerService::OnAgent('" . $workflowId . "', '" . $activityName . "'";

		return $this->hasActiveAgentWithNamePrefix($namePrefix)
			|| $this->hasPendingRepeatEventAgent($workflowId, $activityName);
	}

	private function hasPendingRepeatEventAgent(string $workflowId, string $activityName): bool
	{
		$event = SchedulerEventTable::query()
			->setSelect(['ID'])
			->where('WORKFLOW_ID', $workflowId)
			->where('HANDLER', $activityName)
			->setLimit(1)
			->fetch()
		;

		if (!$event || empty($event['ID']))
		{
			return false;
		}

		$eventId = (int)$event['ID'];

		return $this->hasActiveAgentWithNamePrefix("CBPSchedulerService::repeatEvent({$eventId},");
	}

	/**
	 * The name of an agent starts with the handler call, so the pattern is anchored left and hits
	 * ix_agent_name. CAgent::GetList escapes the value itself (ForSqlLike), so escaping it here would be
	 * applied twice; what survives escaping is the wildcard meaning of an underscore inside a name, hence
	 * the recheck of the fetched row.
	 *
	 * A found agent counts whatever its NEXT_EXEC says: an overdue schedule means a lagging ExecuteAgents
	 * (it picks exactly the rows with NEXT_EXEC <= now), not a missing wake-up mechanism. Only an
	 * inactive agent is no mechanism - the scheduler never fires it.
	 */
	private function hasActiveAgentWithNamePrefix(string $namePrefix): bool
	{
		$agentIterator = \CAgent::GetList(
			['ID' => 'DESC'],
			[
				'MODULE_ID' => 'bizproc',
				'ACTIVE' => 'Y',
				'NAME' => $namePrefix . '%',
			],
		);

		while ($agent = $agentIterator->Fetch())
		{
			if (str_starts_with((string)($agent['NAME'] ?? ''), $namePrefix))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The messenger stores a payload with escaped unicode, so the name of the event is compared after
	 * decoding and not by a pattern over the raw column. ITEM_ID is indexed and leaves units of rows.
	 * Any alive message of the pause counts: a dead retry loop next to a healthy scheduled wake-up does
	 * not make the pause stuck.
	 *
	 * @param array{MODIFIED?: DateTime|null} $instance
	 */
	private function hasPendingResumeMessage(string $workflowId, string $activityName, array $instance): bool
	{
		$statuses = [MessageStatus::New->value, MessageStatus::Processing->value];

		$dbResult = WorkflowResumeMessageTable::query()
			->setSelect(['PAYLOAD', 'AVAILABLE_AT'])
			->whereIn('QUEUE_ID', ResumeWorkflowQueue::values())
			->where('ITEM_ID', $workflowId)
			->whereIn('STATUS', $statuses)
			->exec()
		;

		while ($message = $dbResult->fetch())
		{
			if ($this->extractEventNameFromResumePayload((string)($message['PAYLOAD'] ?? '')) !== $activityName)
			{
				continue;
			}

			if ($this->isResumeMessageAlive($message, $instance))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array{AVAILABLE_AT?: DateTime|null} $message
	 * @param array{MODIFIED?: DateTime|null} $instance
	 */
	private function isResumeMessageAlive(array $message, array $instance): bool
	{
		// An overdue schedule means a lagging queue, not a missing wake-up. A message out of retries needs
		// no check either: the broker deletes such a row instead of leaving it in the queue. A retry loop
		// over a permanently locked instance is caught earlier by the stale-lock check.
		$availableAt = $message['AVAILABLE_AT'] ?? null;
		if (!$availableAt instanceof DateTime)
		{
			return false;
		}

		return !$this->isResumeMessageStaleRelativeToInstance($availableAt, $instance);
	}

	private function isResumeMessageStaleRelativeToInstance(DateTime $availableAt, array $instance): bool
	{
		$modified = $instance['MODIFIED'] ?? null;
		if (!$modified instanceof DateTime)
		{
			return false;
		}

		return $availableAt->getTimestamp() <= $modified->getTimestamp();
	}

	private function getWakeScheduleThreshold(): int
	{
		return time() - WorkflowInstanceTable::LOCKED_TIME_INTERVAL;
	}

	private function resolvePauseDeadlineFromTracking(string $workflowId, string $activityName): ?int
	{
		$row = TrackingTable::query()
			->setSelect(['ACTION_NOTE'])
			->where('WORKFLOW_ID', $workflowId)
			->where('ACTION_NAME', $activityName)
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;

		if (!$row || empty($row['ACTION_NOTE']))
		{
			return null;
		}

		if (!preg_match('/\[timestamp=(\d+)\]/i', (string)$row['ACTION_NOTE'], $matches))
		{
			return null;
		}

		return (int)$matches[1];
	}

	private function getStaleModifiedThreshold(): int
	{
		$graceDays = max(1, (int)Option::get('bizproc', 'clear_stuck_pause_grace_days', self::DEFAULT_GRACE_DAYS));

		return time() - $graceDays * 86400;
	}

	/**
	 * @param array{MODIFIED?: DateTime|null, STATUS?: int|null, OWNER_ID?: string|null, OWNED_UNTIL?: DateTime|null} $instance
	 */
	private function isStuckStalePauseWithoutWorkflow(string $workflowId, array $instance): bool
	{
		$pauseActivities = $this->resolvePauseActivitiesFromExternalSignals($workflowId);
		if ($pauseActivities === [])
		{
			return false;
		}

		foreach ($pauseActivities as $pauseActivity)
		{
			if ($this->hasValidWakeMechanism($workflowId, $pauseActivity, $instance))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @return StuckPauseActivityInfo[]
	 */
	private function resolvePauseActivitiesFromExternalSignals(string $workflowId): array
	{
		$candidateNames = array_unique(array_merge(
			$this->collectEventNamesFromResumeMessages($workflowId),
			$this->collectEventNamesFromSchedulerHandlers($workflowId),
			$this->collectEventNamesFromDelayAgents($workflowId),
			$this->collectEventNamesFromExecutingTracking($workflowId),
		));

		$result = [];
		foreach ($candidateNames as $activityName)
		{
			if ($activityName === '')
			{
				continue;
			}

			$type = $this->resolvePauseActivityType($workflowId, $activityName);
			if ($type === null)
			{
				continue;
			}

			$result[] = new StuckPauseActivityInfo($activityName, $type);
		}

		return $result;
	}

	/**
	 * @return string[]
	 */
	private function collectEventNamesFromResumeMessages(string $workflowId): array
	{
		$statuses = [MessageStatus::New->value, MessageStatus::Processing->value];

		$result = [];

		$dbResult = WorkflowResumeMessageTable::query()
			->setSelect(['PAYLOAD'])
			->whereIn('QUEUE_ID', ResumeWorkflowQueue::values())
			->where('ITEM_ID', $workflowId)
			->whereIn('STATUS', $statuses)
			->exec()
		;

		while ($message = $dbResult->fetch())
		{
			$eventName = $this->extractEventNameFromResumePayload((string)($message['PAYLOAD'] ?? ''));
			if ($eventName !== null)
			{
				$result[] = $eventName;
			}
		}

		return $result;
	}

	/**
	 * @return string[]
	 */
	private function collectEventNamesFromSchedulerHandlers(string $workflowId): array
	{
		$dbResult = SchedulerEventTable::query()
			->setSelect(['HANDLER'])
			->where('WORKFLOW_ID', $workflowId)
			->exec()
		;

		$result = [];
		while ($row = $dbResult->fetch())
		{
			$handler = (string)($row['HANDLER'] ?? '');
			if ($handler !== '')
			{
				$result[] = $handler;
			}
		}

		return $result;
	}

	/**
	 * @return string[]
	 */
	private function collectEventNamesFromDelayAgents(string $workflowId): array
	{
		$result = [];
		$namePrefix = "CBPSchedulerService::OnAgent('" . $workflowId . "', '";
		$agentIterator = \CAgent::GetList(
			['ID' => 'DESC'],
			[
				'MODULE_ID' => 'bizproc',
				'NAME' => $namePrefix . '%',
			],
		);

		while ($agent = $agentIterator->Fetch())
		{
			$agentName = (string)($agent['NAME'] ?? '');
			$eventName = str_starts_with($agentName, $namePrefix)
				? $this->extractEventNameFromOnAgent($agentName)
				: null
			;

			if ($eventName !== null)
			{
				$result[] = $eventName;
			}
		}

		return $result;
	}

	/**
	 * @return string[]
	 */
	private function collectEventNamesFromExecutingTracking(string $workflowId): array
	{
		$rows = TrackingTable::query()
			->setSelect(['ACTION_NAME'])
			->where('WORKFLOW_ID', $workflowId)
			->where('EXECUTION_STATUS', CBPActivityExecutionStatus::Executing)
			->exec()
		;

		$result = [];
		while ($row = $rows->fetch())
		{
			$actionName = (string)($row['ACTION_NAME'] ?? '');
			if ($actionName !== '')
			{
				$result[] = $actionName;
			}
		}

		return $result;
	}

	private function resolvePauseActivityType(string $workflowId, string $activityName): ?string
	{
		$templateId = $this->getWorkflowTemplateId($workflowId);
		$template = $this->loadWorkflowTemplate($templateId);
		if ($template === null)
		{
			return null;
		}

		$activity = \CBPWorkflowTemplateLoader::FindActivityByName($template, $activityName);
		if (!is_array($activity))
		{
			return null;
		}

		$type = (string)($activity['Type'] ?? '');
		if (!in_array($type, PauseActivityType::ALL, true))
		{
			return null;
		}

		return $type;
	}

	private function getWorkflowTemplateId(string $workflowId): int
	{
		$row = WorkflowInstanceTable::query()
			->setSelect(['WORKFLOW_TEMPLATE_ID'])
			->where('ID', $workflowId)
			->fetch()
		;

		if (!empty($row['WORKFLOW_TEMPLATE_ID']))
		{
			return (int)$row['WORKFLOW_TEMPLATE_ID'];
		}

		return 0;
	}

	private function loadWorkflowTemplate(int $templateId): ?array
	{
		if ($templateId <= 0)
		{
			return null;
		}

		if (array_key_exists($templateId, $this->templateCache))
		{
			return $this->templateCache[$templateId];
		}

		$iterator = \CBPWorkflowTemplateLoader::GetList(
			[],
			['ID' => $templateId],
			false,
			false,
			['TEMPLATE'],
		);
		$row = $iterator->Fetch();
		$template = is_array($row['TEMPLATE'] ?? null) ? $row['TEMPLATE'] : null;
		$this->templateCache[$templateId] = $template;

		return $template;
	}

	private function extractEventNameFromResumePayload(string $payload): ?string
	{
		if ($payload === '')
		{
			return null;
		}

		$decoded = json_decode($payload, true);
		if (is_array($decoded) && !\CBPHelper::isEmptyValue($decoded['eventName'] ?? null))
		{
			return (string)$decoded['eventName'];
		}

		if (preg_match('/"eventName"\s*:\s*"([^"]+)"/', $payload, $matches))
		{
			return $matches[1];
		}

		return null;
	}

	private function extractEventNameFromOnAgent(string $agentName): ?string
	{
		if (preg_match("/OnAgent\\('[^']+',\\s*'([^']+)'/", $agentName, $matches))
		{
			return $matches[1];
		}

		return null;
	}
}

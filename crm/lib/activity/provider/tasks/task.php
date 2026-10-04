<?php

namespace Bitrix\Crm\Activity\Provider\Tasks;

use Bitrix\Crm\Activity\Provider\Base;
use Bitrix\Crm\Activity\TodoPingSettingsProvider;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Badge;
use Bitrix\Crm\EO_Activity;
use Bitrix\Crm\Integration\Analytics;
use Bitrix\Crm\Integration\Tasks\Task2ActivityPriority;
use Bitrix\Crm\Integration\Tasks\Task2ActivityStatus;
use Bitrix\Crm\Integration\Tasks\TaskAccessController;
use Bitrix\Crm\Integration\Tasks\TaskHandler;
use Bitrix\Crm\Integration\Tasks\TaskObject;
use Bitrix\Crm\Integration\Tasks\TaskPathMaker;
use Bitrix\Crm\Integration\Tasks\TaskSliderFactory;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\Entity\TimelineBindingTable;
use Bitrix\Crm\Timeline\Entity\TimelineTable;
use Bitrix\Crm\Timeline\TimelineEntry;
use Bitrix\Crm\Timeline\TimelineType;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Uri;
use Bitrix\Tasks\Integration\CRM\Timeline\Bindings;
use Bitrix\Tasks\V2\Public\Entity\TaskState;
use Bitrix\Tasks\V2\Public\Provider\TaskStateProvider;
use CCrmActivity;
use CCrmActivityStatus;
use CCrmDateTimeHelper;
use CCrmOwnerType;
use CCrmOwnerTypeAbbr;

final class Task extends Base
{
	use ActivityTrait;

	private const PROVIDER_ID = 'CRM_TASKS_TASK';
	private const PROVIDER_TYPE_ID = 'TASKS_TASK';
	private const SUBJECT = 'TASK';
	private const TASK_CRM_FIELD = 'UF_CRM_TASK';
	private const UPDATE_OPTIONS = ['SKIP_ASSOCIATED_ENTITY' => true, 'REGISTER_SONET_EVENT' => false];
	private const MAX_STATE_SYNC_ATTEMPTS = 3;
	public const ERROR_ACTIVITY_NOT_FOUND = 'CRM_TASK_ACTIVITY_NOT_FOUND';
	public const ERROR_CURRENT_STATUS_NOT_FOUND = 'CRM_TASK_ACTIVITY_CURRENT_STATUS_NOT_FOUND';
	public const ERROR_STATUS_TRANSITION_NOT_ALLOWED = 'CRM_TASK_ACTIVITY_STATUS_TRANSITION_NOT_ALLOWED';
	public const ERROR_STATUS_UPDATE_FAILED = 'CRM_TASK_ACTIVITY_STATUS_UPDATE_FAILED';
	public const ERROR_TASK_NOT_FOUND = 'CRM_TASK_NOT_FOUND';
	public const ERROR_COMPLETION_UPDATE_FAILED = 'CRM_TASK_ACTIVITY_COMPLETION_UPDATE_FAILED';
	public const ERROR_END_TIME_UPDATE_FAILED = 'CRM_TASK_ACTIVITY_END_TIME_UPDATE_FAILED';
	public const ERROR_TASK_STATE_CHANGED = 'CRM_TASK_STATE_CHANGED_DURING_SYNC';

	public static array $cache = [];

	public static function getId(): string
	{
		return self::PROVIDER_ID;
	}

	public static function getProviderTypeId(): string
	{
		return self::PROVIDER_TYPE_ID;
	}

	public static function getSubject(): string
	{
		return self::SUBJECT;
	}

	public static function getName()
	{
		return Loc::getMessage('TASKS_TASK_INTEGRATION_TASK_V2') ?? Loc::getMessage('TASKS_TASK_INTEGRATION_TASK');
	}

	public static function getTypes(): array
	{
		return [
			[
				'NAME' => self::getName(),
				'PROVIDER_ID' => self::getId(),
				'PROVIDER_TYPE_ID' => self::getProviderTypeId(),
			],
		];
	}

	public static function getDefaultPingOffsets(array $params = []): array
	{
		return TodoPingSettingsProvider::DEFAULT_OFFSETS;
	}

	public function delete(int $activityId): void
	{
		CCrmActivity::Delete($activityId, false, true, ['MOVED_TO_RECYCLE_BIN' => true]);
		TimelineEntry::deleteByAssociatedEntity(CCrmOwnerType::Activity, $activityId);

		static::invalidateAll();
	}

	public function updateFiles(EO_Activity $activity, array $timelineParams): void
	{
		if (isset($timelineParams['TASK_FILE_IDS']))
		{
			$this->update($activity->getId(), [
				'STORAGE_ELEMENT_IDS' => $timelineParams['TASK_FILE_IDS'],
			]);
		}

		self::invalidate($this->getCacheKey($timelineParams['TASK_ID']));
	}

	public function updateDeadline(int $taskId, array $timelineParams): void
	{
		$activity = $this->find($taskId);
		if (!$activity)
		{
			return;
		}

		$desiredDeadline = (isset($timelineParams['DEADLINE']) && $timelineParams['DEADLINE'] instanceof DateTime)
			? $timelineParams['DEADLINE']->toString()
			: CCrmDateTimeHelper::GetMaxDatabaseDate(false);

		$this->update(
			$activity->getId(),
			[
				'END_TIME' => $desiredDeadline,
				'PROVIDER_ID' => $activity->getProviderId(),
				'ASSOCIATED_ENTITY_ID' => $taskId,
			]
		);
	}

	public function prepareFields(int $taskId, Bindings $bindings, array $timelineParams): array
	{
		$bindings = $bindings->toArray('OWNER_ID', 'OWNER_TYPE_ID');
		$task = TaskObject::getObject($taskId);
		$status = (int)$task->getStatus();
		$fields = [
			'ASSOCIATED_ENTITY_ID' => $taskId,
			'BINDINGS' => $bindings,
			'RESPONSIBLE_ID' => $task->getResponsibleMemberId(),
			'SUBJECT' => $task->getTitle(),
			'SETTINGS' => $timelineParams,
			'DESCRIPTION' => $task->getDescription(),
			'START_TIME' => is_null($task->getStartDatePlan()) ? '' : $task->getStartDatePlan()->toString(),
			'END_TIME' => is_null($task->getEndDatePlan()) ? '' : $task->getEndDatePlan()->toString(),
			'PRIORITY' => Task2ActivityPriority::getPriority((int)$task->getPriority()),
			'COMPLETED' => $status === TaskActivityStatus::TASKS_STATE_COMPLETED || $status === TaskActivityStatus::TASKS_STATE_SUPPOSEDLY_COMPLETED,
			'AUTHOR_ID' => $timelineParams['AUTHOR_ID'],
		];

		if (!empty($timelineParams['TASK_FILE_IDS']))
		{
			$fields['STORAGE_ELEMENT_IDS'] = $timelineParams['TASK_FILE_IDS'];
		}

		return $fields;
	}


	public function updateDescription(array $timelineParams): void
	{
		$taskId = $timelineParams['TASK_ID'];
		if (is_null($taskId))
		{
			return;
		}

		$task = TaskObject::getObject($taskId);
		if (is_null($task))
		{
			return;
		}

		$description = $task->getDescription();
		if (is_null($description))
		{
			return;
		}

		$activity = $this->find($taskId);
		if (is_null($activity))
		{
			return;
		}

		if ($activity->getDescription() === $description)
		{
			return;
		}

		$this->update($activity->getId(),[
			'DESCRIPTION' => $description,
		]);
	}
	public function setEndTime(?EO_Activity $activity, ?DateTime $time): void
	{
		if (is_null($activity))
		{
			return;
		}

		$time = is_null($time) ? '' : $time->toString();
		$this->update($activity->getId(),[
			'END_TIME' => $time
		]);
	}


	public function updateByTask(array $timelineParams): void
	{
		$taskId = $timelineParams['TASK_ID'] ?? null;
		if (is_null($taskId))
		{
			return;
		}

		$task = TaskObject::getObject($taskId);
		if (is_null($task))
		{
			return;
		}

		$activity = $this->find($taskId);
		if (is_null($activity))
		{
			return;
		}

		$updateData = [];
		$activityStartTime = is_null($activity->getStartTime()) ? '' : $activity->getStartTime()->toString();
		$taskStartDatePlan = is_null($task->getStartDatePlan()) ? '' : $task->getStartDatePlan()->toString();

		if ($activityStartTime !== $taskStartDatePlan)
		{
			$updateData['START_TIME'] = $taskStartDatePlan;
		}

		if ($activity->getResponsibleId() !== $task->getResponsibleMemberId())
		{
			$updateData['RESPONSIBLE_ID'] = $task->getResponsibleMemberId();
		}

		if ($activity->getSubject() !== $task->getTitle())
		{
			$updateData['SUBJECT'] = $task->getTitle();
		}

		if ($activity->getPriority() !== (int)$task->getPriority())
		{
			$updateData['PRIORITY'] = Task2ActivityPriority::getPriority((int)$task->getPriority());
		}

		if (!empty($updateData))
		{
			$updateData['ASSOCIATED_ENTITY_ID'] = $task->getId();
			$this->update($activity->getId(), $updateData);
		}
	}

	public function find(int $taskId, $force = false): ?EO_Activity
	{
		if ($taskId <= 0)
		{
			return null;

		}

		$key = $this->getCacheKey($taskId);
		if (isset(static::$cache[$key]) && !$force)
		{
			return static::$cache[$key];
		}

		$task = TaskObject::getObject($taskId, true);
		if (is_null($task))
		{
			return null;
		}

		try
		{
			$query = self::prepareQuery($taskId);
			self::$cache[$key] = $query->exec()->fetchObject();
		}
		catch (SystemException $exception)
		{
			return null;
		}

		return self::$cache[$key];
	}

	private static function prepareQuery(int $taskId)
	{
		$query = ActivityTable::query();
		$query
			->addSelect('ID')
			->addSelect('TYPE_ID')
			->addSelect('PROVIDER_ID')
			->addSelect('PROVIDER_TYPE_ID')
			->addSelect('COMPLETED')
			->addSelect('SUBJECT')
			->addSelect('RESPONSIBLE_ID')
			->addSelect('SETTINGS')
			->addSelect('STORAGE_TYPE_ID')
			->addSelect('STORAGE_ELEMENT_IDS')
			->addSelect('CREATED')
			->addSelect('LAST_UPDATED')
			->addSelect('START_TIME')
			->addSelect('END_TIME')
			->addSelect('PRIORITY')
			->where('ASSOCIATED_ENTITY_ID', $taskId)
			->where('PROVIDER_ID', self::getId())
			->where('PROVIDER_TYPE_ID', self::getProviderTypeId())
		;

		return $query;
	}
	public static function checkFields($action, &$fields, $id, $params = null)
	{
		$result = new Result();
		$taskId = $fields['ASSOCIATED_ENTITY_ID'] ?? null;
		if (is_null($taskId))
		{
			return $result;
		}

		$task = TaskObject::getObject($taskId);
		if (is_null($task))
		{
			return $result;
		}

		$deadline = $task->getDeadline();
		if (!is_null($deadline))
		{
			$fields['DEADLINE'] = $deadline->toString();
		}
		else
		{
			$fields['DEADLINE'] = CCrmDateTimeHelper::GetMaxDatabaseDate(false);
		}

		return $result;
	}

	public function updateStatus(int $taskId, string $desiredStatus): Result
	{
		$result = new Result();
		$activity = $this->find($taskId, true);
		if (is_null($activity))
		{
			return $result->addError(new Error(
				'Task activity was not found.',
				self::ERROR_ACTIVITY_NOT_FOUND,
				['taskId' => $taskId, 'desiredStatus' => $desiredStatus],
			));
		}

		$settings = $activity->getSettings();
		$settings = is_array($settings) ? $settings : [];
		$currentStatus = $settings['ACTIVITY_STATUS'] ?? null;

		$taskActivityStatus = new TaskActivityStatus();
		if ($taskActivityStatus->isTaskStatusProjection($desiredStatus))
		{
			// the task state is authoritative, the transition matrix does not gate it
			if ($currentStatus !== $desiredStatus)
			{
				return $this->applyStatus($activity, $settings, $desiredStatus);
			}

			return $result;
		}

		if (is_null($currentStatus))
		{
			return $result->addError(new Error(
				'Task activity current status was not found.',
				self::ERROR_CURRENT_STATUS_NOT_FOUND,
				['taskId' => $taskId, 'desiredStatus' => $desiredStatus],
			));
		}

		if (!$taskActivityStatus->isAllowedStatusChange($desiredStatus, $currentStatus))
		{
			return $result->addError(new Error(
				'Task activity status transition is not allowed.',
				self::ERROR_STATUS_TRANSITION_NOT_ALLOWED,
				[
					'taskId' => $taskId,
					'desiredStatus' => $desiredStatus,
					'currentStatus' => $currentStatus,
				],
			));
		}

		return $this->applyStatus($activity, $settings, $desiredStatus);
	}

	private function applyStatus(EO_Activity $activity, array $settings, string $status): Result
	{
		$result = new Result();
		$settings['ACTIVITY_STATUS'] = $status;

		$isUpdated = CCrmActivity::Update(
			$activity->getId(),
			[
				'SETTINGS' => $settings,
			],
			false,
			true,
			self::UPDATE_OPTIONS,
		);
		if (!$isUpdated)
		{
			$result->addError(new Error(
				'Task activity status could not be updated.',
				self::ERROR_STATUS_UPDATE_FAILED,
				['activityId' => $activity->getId(), 'desiredStatus' => $status],
			));
		}

		return $result;
	}

	public static function updateAssociatedEntity($entityId, array $activity, array $options = []): Result
	{
		$result = new Result();

		$taskId = (int)$entityId;
		$responsibleId = (int)($activity['RESPONSIBLE_ID'] ?? null);

		if ($taskId <= 0 || $responsibleId <= 0)
		{
			$result->addError(new Error('Wrong task or responsible id.'));
			return $result;
		}

		$task = TaskObject::getObject($taskId);
		if (is_null($task))
		{
			return $result;
		}
		$bindings = $activity['BINDINGS'] ?? [];
		$crmFields = self::prepareBindingsToTask($bindings);
		$taskCrmFields = $task->getCrmFields();

		$updateData = [];

		$status = (int)$task->getStatus();
		if (
			$status !== TaskActivityStatus::TASKS_STATE_COMPLETED
			&& $status !== TaskActivityStatus::TASKS_STATE_SUPPOSEDLY_COMPLETED
			&& $activity['COMPLETED'] === 'Y'
			&& $task->getTaskControl()
		)
		{
			if ($task->getResponsibleMemberId() === $task->getCreatedByMemberId())
			{
				$updateData['STATUS'] = TaskActivityStatus::TASKS_STATE_COMPLETED;
			}
			else
			{
				$updateData['STATUS'] = TaskActivityStatus::TASKS_STATE_SUPPOSEDLY_COMPLETED;
			}
		}
		elseif (
			$status !== TaskActivityStatus::TASKS_STATE_COMPLETED
			&& $status !== TaskActivityStatus::TASKS_STATE_SUPPOSEDLY_COMPLETED
			&& $activity['COMPLETED'] === 'Y'
			&& !$task->getTaskControl()
		)
		{
			$updateData['STATUS'] = TaskActivityStatus::TASKS_STATE_COMPLETED;
		}
		elseif (
			$status === TaskActivityStatus::TASKS_STATE_COMPLETED
			&& $activity['COMPLETED'] === 'N'
		)
		{
			$updateData['STATUS'] = TaskActivityStatus::TASKS_STATE_PENDING;
		}
		elseif (
			$status === TaskActivityStatus::TASKS_STATE_SUPPOSEDLY_COMPLETED
			&& $activity['COMPLETED'] === 'N'
		)
		{
			$updateData['STATUS'] = TaskActivityStatus::TASKS_STATE_PENDING;
		}

		if (
			!empty(array_diff($crmFields, $taskCrmFields))
			|| !empty(array_diff($taskCrmFields, $crmFields))
		)
		{
			$updateData[self::TASK_CRM_FIELD] = $crmFields;
		}

		if ($task->getTitle() !== $activity['SUBJECT'])
		{
			$updateData['TITLE'] = $activity['SUBJECT'];
		}

		if ($task->getDescription() !== $activity['DESCRIPTION'])
		{
			$updateData['DESCRIPTION'] = $activity['DESCRIPTION'];
		}

		if ($task->getResponsibleMemberId() !== (int)$activity['RESPONSIBLE_ID'])
		{
			$updateData['RESPONSIBLE_ID'] = $activity['RESPONSIBLE_ID'];
		}

		$startDatePlan = is_null($task->getStartDatePlan()) ? '' : $task->getStartDatePlan()->toString();
		if ($activity['START_TIME'] !== $startDatePlan)
		{
			$updateData['START_DATE_PLAN']
				= CCrmDateTimeHelper::IsMaxDatabaseDate($activity['START_TIME'])
					? null
					: $activity['START_TIME'];
		}

		$endDatePlan = is_null($task->getEndDatePlan()) ? '' : $task->getEndDatePlan()->toString();
		if ($activity['END_TIME'] !== $endDatePlan)
		{
			$updateData['END_DATE_PLAN']
				= CCrmDateTimeHelper::IsMaxDatabaseDate($activity['END_TIME'])
					? null
					: $activity['END_TIME'];
		}

		if (!empty($updateData))
		{
			$executorId = (int)($options['EXECUTOR_ID'] ?? $options['~CURRENT_USER'] ?? null);
			$executorId = $executorId > 0 ? $executorId : $responsibleId;

			$handler = TaskHandler::getHandler($executorId)
				->withAutoClose();

			if (method_exists($handler, 'withAnalyticsEvent'))
			{
				$handler->withAnalyticsEvent(self::getAnalyticsEvent($updateData, $activity, $options));
			}

			try
			{
				$handler->update($taskId, $updateData);
			}
			catch (\Exception $exception)
			{
				$result->addError(new Error($exception->getMessage()));
			}
		}

		return $result;
	}

	private static function getAnalyticsEvent(array $updateData, array $activity, array $options): ?AnalyticsEvent
	{
		$event = $options['ANALYTICS_EVENT'] ?? null;

		if ($event instanceof AnalyticsEvent)
		{
			return $event;
		}

		$taskStatus = (int)($updateData['STATUS'] ?? 0);
		$activityStatus = (int)($activity['STATUS'] ?? 0);

		if (self::isTaskCompletedByAutoCompletedActivity($taskStatus, $activityStatus))
		{
			$event = new AnalyticsEvent(
				event: Analytics\Tasks\Event::TaskComplete->value,
				tool: Analytics\Dictionary::TOOL_TASKS,
				category: Analytics\Tasks\Category::TaskOperations->value,
			);

			return
				$event
					->setSection(Analytics\Tasks\Section::Crm->value)
					->setSubSection(Analytics\Tasks\SubSection::Automation->value)
					->setElement(Analytics\Tasks\Element::Auto->value)
			;
		}

		return null;
	}

	private static function isTaskCompletedByAutoCompletedActivity(int $taskStatus, int $activityStatus): bool
	{
		$isTaskCompleted = (
			$taskStatus === TaskActivityStatus::TASKS_STATE_SUPPOSEDLY_COMPLETED
			|| $taskStatus === TaskActivityStatus::TASKS_STATE_COMPLETED
		);

		$isActivityAutoCompleted = $activityStatus === CCrmActivityStatus::AutoCompleted;

		return $isTaskCompleted && $isActivityAutoCompleted;
	}

	// public function updateEndTime(array $timelineParams): void
	// {
	// 	$taskId = $timelineParams['TASK_ID'] ?? null;
	// 	if (is_null($taskId))
	// 	{
	// 		return;
	// 	}
	//
	// 	$task = TaskObject::getObject($taskId);
	// 	if (is_null($task))
	// 	{
	// 		return;
	// 	}
	//
	// 	$closedDate = $task->getClosedDate();
	// }

	/**
	 * Derives both copies of the task state kept by the activity - the completion flag and the status label -
	 * from the current task state and applies them.
	 */
	public function syncStateWithTask(int $taskId): TaskStateSyncResult
	{
		$result = new TaskStateSyncResult();
		if ($taskId <= 0)
		{
			return $result->addError(new Error(
				'Task was not found.',
				self::ERROR_TASK_NOT_FOUND,
				['taskId' => $taskId],
			));
		}

		if (!$this->isTaskStateProviderAvailable())
		{
			return $result;
		}

		$taskState = $this->getCurrentTaskState($taskId);
		if ($taskState === null)
		{
			return $result->addError(new Error(
				'Task was not found.',
				self::ERROR_TASK_NOT_FOUND,
				['taskId' => $taskId],
			));
		}

		$activity = $this->find($taskId, true);
		if ($activity === null)
		{
			return $result->addError(new Error(
				'Task activity was not found.',
				self::ERROR_ACTIVITY_NOT_FOUND,
				['taskId' => $taskId],
			));
		}

		$initialActivityState = $this->captureState($activity);
		$result->setInitialActivityState($initialActivityState);
		$activityWasChanged = false;
		$completionWasCreated = false;

		for ($attempt = 1; $attempt <= self::MAX_STATE_SYNC_ATTEMPTS; $attempt++)
		{
			$taskActivityStatus = new TaskActivityStatus();
			$desiredStatus = $taskActivityStatus->onStatusChange($taskState['status'], $taskState['isExpired']);
			$desiredCompleted = null;
			if ($desiredStatus === '')
			{
				if ($activityWasChanged)
				{
					$restoreResult = $this->restoreInitialState($taskId, $initialActivityState, $result);
					if (!$restoreResult->isSuccess())
					{
						return $result->addErrors($restoreResult->getErrors());
					}

					$activityWasChanged = false;
				}
			}
			else
			{
				$desiredCompleted = $taskActivityStatus->isCompletedTaskState($taskState['status']);
				$completionWasCreated = $completionWasCreated
					|| (!$activity->getCompleted() && $desiredCompleted)
				;
				$activityWasChanged = $activityWasChanged || $this->isStateUpdateRequired(
					$activity,
					$desiredCompleted,
					$desiredStatus,
				);
				if (
					$activity->getCompleted() !== $desiredCompleted
					&& $initialActivityState->completedEntryIds === null
				)
				{
					$initialActivityState = new TaskActivityState(
						activityId: $initialActivityState->activityId,
						completed: $initialActivityState->completed,
						status: $initialActivityState->status,
						endTime: $initialActivityState->endTime,
						completedEntryIds: $this->getCompletedActivityEntryIds($activity->getId(), $taskId),
					);
					$result->setInitialActivityState($initialActivityState);
				}

				$syncResult = $this->setState($activity, $desiredCompleted, $desiredStatus);
				if (!$syncResult->isSuccess())
				{
					return $result->addErrors($syncResult->getErrors());
				}
			}

			$currentTaskState = $this->getCurrentTaskState($taskId);
			if ($currentTaskState === $taskState)
			{
				if ($desiredCompleted === false && $completionWasCreated)
				{
					$this->deleteTechnicalCompletionEntries($taskId, $initialActivityState, $result);
				}

				return $result;
			}

			if ($currentTaskState === null)
			{
				return $result->addError(new Error(
					'Task was not found.',
					self::ERROR_TASK_NOT_FOUND,
					['taskId' => $taskId],
				));
			}

			$taskState = $currentTaskState;
			$activity = $this->find($taskId, true);
			if ($activity === null)
			{
				return $result->addError(new Error(
					'Task activity was not found.',
					self::ERROR_ACTIVITY_NOT_FOUND,
					['taskId' => $taskId],
				));
			}
		}

		if ($activityWasChanged)
		{
			$restoreResult = $this->restoreInitialState($taskId, $initialActivityState, $result);
			if (!$restoreResult->isSuccess())
			{
				$result->addErrors($restoreResult->getErrors());
			}
		}

		return $result->addError(new Error(
			'Task state kept changing while its activity was being synchronized.',
			self::ERROR_TASK_STATE_CHANGED,
			['taskId' => $taskId],
		));
	}

	private function captureState(EO_Activity $activity): TaskActivityState
	{
		$settings = $activity->getSettings();
		$endTime = $activity->getEndTime();

		return new TaskActivityState(
			activityId: $activity->getId(),
			completed: $activity->getCompleted(),
			status: is_array($settings) ? ($settings['ACTIVITY_STATUS'] ?? null) : null,
			endTime: $endTime === null ? null : clone $endTime,
			completedEntryIds: null,
		);
	}

	private function isStateUpdateRequired(EO_Activity $activity, bool $completed, string $status): bool
	{
		$settings = $activity->getSettings();

		return $activity->getCompleted() !== $completed
			|| !is_array($settings)
			|| ($settings['ACTIVITY_STATUS'] ?? null) !== $status
		;
	}

	private function restoreInitialState(
		int $taskId,
		TaskActivityState $initialState,
		TaskStateSyncResult $syncResult,
	): Result
	{
		$result = new Result();
		$activity = $this->find($taskId, true);
		if ($activity === null)
		{
			return $result->addError(new Error(
				'Task activity was not found.',
				self::ERROR_ACTIVITY_NOT_FOUND,
				['taskId' => $taskId],
			));
		}

		$restoreResult = $this->updateState(
			activity: $activity,
			completed: $initialState->completed,
			status: $initialState->status,
			updateStatus: true,
			updateEndTime: false,
			endTime: null,
		);
		if (!$restoreResult->isSuccess())
		{
			return $result->addErrors($restoreResult->getErrors());
		}

		$this->deleteTechnicalCompletionEntries($taskId, $initialState, $syncResult);

		return $result;
	}

	private function deleteTechnicalCompletionEntries(
		int $taskId,
		TaskActivityState $initialState,
		TaskStateSyncResult $syncResult,
	): void
	{
		if ($initialState->completedEntryIds === null)
		{
			return;
		}

		$currentEntryIds = $this->getCompletedActivityEntryIds($initialState->activityId, $taskId);
		$technicalEntryIds = array_values(array_diff($currentEntryIds, $initialState->completedEntryIds));
		foreach ($technicalEntryIds as $entryId)
		{
			TimelineEntry::delete($entryId);
		}

		$syncResult->addDeletedCompletionEntryIds($technicalEntryIds);
	}

	public function setState(EO_Activity $activity, bool $completed, ?string $status = null): Result
	{
		return $this->updateState(
			activity: $activity,
			completed: $completed,
			status: $status,
			updateStatus: $status !== null,
			updateEndTime: false,
			endTime: null,
		);
	}

	public function restoreState(
		EO_Activity $activity,
		bool $completed,
		?string $status,
		?DateTime $endTime,
	): Result
	{
		return $this->updateState(
			activity: $activity,
			completed: $completed,
			status: $status,
			updateStatus: true,
			updateEndTime: true,
			endTime: $endTime,
		);
	}

	private function updateState(
		EO_Activity $activity,
		bool $completed,
		?string $status,
		bool $updateStatus,
		bool $updateEndTime,
		?DateTime $endTime,
	): Result
	{
		$result = new Result();
		$settings = $activity->getSettings();
		$settings = is_array($settings) ? $settings : [];

		$isCompletionUpdateRequired = $activity->getCompleted() !== $completed;
		$isStatusUpdateRequired = $updateStatus && ($settings['ACTIVITY_STATUS'] ?? null) !== $status;
		if (!$isCompletionUpdateRequired && !$isStatusUpdateRequired && !$updateEndTime)
		{
			return $result;
		}

		$fields = [];
		if ($isCompletionUpdateRequired)
		{
			$fields['COMPLETED'] = $completed ? 'Y' : 'N';
		}

		if ($isStatusUpdateRequired)
		{
			if ($status === null)
			{
				unset($settings['ACTIVITY_STATUS']);
			}
			else
			{
				$settings['ACTIVITY_STATUS'] = $status;
			}

			$fields['SETTINGS'] = $settings;
		}

		if ($updateEndTime)
		{
			$fields['END_TIME'] = $endTime?->toString() ?? '';
		}

		if (CCrmActivity::Update($activity->getId(), $fields, false, true, self::UPDATE_OPTIONS))
		{
			self::invalidateAll();

			return $result;
		}

		if ($isCompletionUpdateRequired)
		{
			$result->addError(new Error(
				'Task activity completion state could not be updated.',
				self::ERROR_COMPLETION_UPDATE_FAILED,
				['activityId' => $activity->getId(), 'completed' => $completed],
			));
		}

		if ($isStatusUpdateRequired)
		{
			$result->addError(new Error(
				'Task activity status could not be updated.',
				self::ERROR_STATUS_UPDATE_FAILED,
				['activityId' => $activity->getId(), 'status' => $status],
			));
		}

		if ($updateEndTime)
		{
			$result->addError(new Error(
				'Task activity end time could not be updated.',
				self::ERROR_END_TIME_UPDATE_FAILED,
				['activityId' => $activity->getId(), 'endTime' => $endTime?->toString()],
			));
		}

		return $result;
	}

	public function syncExpiredStatusWithTask(int $taskId): TaskStateSyncResult
	{
		return $this->syncStateWithTask($taskId);
	}

	/**
	 * @return array{status: int, isExpired: bool}|null
	 */
	public function getCurrentTaskState(int $taskId): ?array
	{
		$taskState = $this->getCurrentTaskStateSnapshot($taskId);
		if ($taskState === null)
		{
			return null;
		}

		return [
			'status' => $taskState->status,
			'isExpired' => $taskState->isExpired,
		];
	}

	public function getCurrentTaskStateSnapshot(int $taskId): ?TaskState
	{
		if (!$this->isTaskStateProviderAvailable())
		{
			return null;
		}

		return (new TaskStateProvider())->getCurrent($taskId);
	}

	private function isTaskStateProviderAvailable(): bool
	{
		return Loader::includeModule('tasks') && class_exists(TaskStateProvider::class);
	}

	/**
	 * Writes nothing when the activity already holds the requested state, so callers may ask for it
	 * unconditionally.
	 */
	public function setCompleted(EO_Activity $activity, bool $completed): Result
	{
		$result = new Result();
		if ($activity->getCompleted() === $completed)
		{
			return $result;
		}

		$isUpdated = $completed
			? CCrmActivity::Complete($activity->getId(), true, self::UPDATE_OPTIONS)
			: CCrmActivity::Update(
				$activity->getId(),
				['COMPLETED' => 'N'],
				false,
				true,
				self::UPDATE_OPTIONS,
			)
		;
		if (!$isUpdated)
		{
			return $result->addError(new Error(
				'Task activity completion state could not be updated.',
				self::ERROR_COMPLETION_UPDATE_FAILED,
				['activityId' => $activity->getId(), 'completed' => $completed],
			));
		}

		self::invalidateAll();

		return $result;
	}

	public function complete(EO_Activity $activity): void
	{
		CCrmActivity::Complete($activity->getId(), true, self::UPDATE_OPTIONS);
		self::invalidateAll();
	}

	public function renew(int $activityId): void
	{
		$this->update(
			$activityId,
			[
				'COMPLETED' => 'N',
			],
		);

		self::invalidateAll();
	}

	public function getCompletedActivityEntryId(int $activityId, int $taskId): int
	{
		return $this->getCompletedActivityEntryIds($activityId, $taskId)[0] ?? 0;
	}

	/**
	 * @return int[]
	 */
	public function getCompletedActivityEntryIds(int $activityId, int $taskId): array
	{
		$completedActivityQuery = TimelineTable::query();
		$completedActivityQuery
			->setSelect(['ID'])
			->where('SOURCE_ID', $taskId)
			->where('ASSOCIATED_ENTITY_ID', $activityId)
			->where('ASSOCIATED_ENTITY_CLASS_NAME', self::getId())
			->where('TYPE_ID', TimelineType::ACTIVITY)
			->where('TYPE_CATEGORY_ID', \CCrmActivityType::Provider)
			->where('ASSOCIATED_ENTITY_TYPE_ID', \CCrmActivityType::Provider)
			->setOrder(['ID' => 'ASC'])
		;

		return array_map(
			static fn($completedActivity): int => $completedActivity->getId(),
			$completedActivityQuery->fetchCollection()->getAll(),
		);
	}
	public static function syncBadges(int $activityId, array $activityFields, array $bindings): void
	{
		$taskStatus = new TaskActivityStatus();
		$status = $activityFields['SETTINGS']['ACTIVITY_STATUS'] ?? null;
		if (!$status || !$taskStatus->isStatusValid($status))
		{
			return;
		}

		$badge = Container::getInstance()->getBadge(
			Badge\Type\TaskStatus::TASK_STATUS_TYPE,
			$status,
		);

		$sourceIdentifier = new Badge\SourceIdentifier(
			Badge\SourceIdentifier::CRM_OWNER_TYPE_PROVIDER,
			CCrmOwnerType::Activity,
			$activityId,
		);

		foreach ($bindings as $singleBinding)
		{
			$itemIdentifier = new ItemIdentifier((int)$singleBinding['OWNER_TYPE_ID'], (int)$singleBinding['OWNER_ID']);
			$badge->unbindWithAnyValue($itemIdentifier, $sourceIdentifier);
			$badge->upsert($itemIdentifier, $sourceIdentifier);
		}
	}

	public function updateBindings(Bindings $newBindings, Bindings $previousBindings, array $timelineParams): void
	{
		$taskId = $timelineParams['TASK_ID'];
		$activity = $this->find($taskId, true);
		$task = TaskObject::getObject($taskId);
		if (is_null($task))
		{
			return;
		}

		if (is_null($activity))
		{
			$timelineParams['ACTIVITY_STATUS'] = Task2ActivityStatus::getStatus((int)$task->getStatus());
			$result = $this->createActivity(
				self::getProviderTypeId(),
				$this->prepareFields($taskId, $newBindings, $timelineParams),
			);
			$activityId = $result->getData()['id'] ?? null;
		}
		else
		{
			$activityId = $activity->getId();
		}

		if (is_null($activityId))
		{
			return;
		}

		if ($newBindings->isEmpty())
		{
			$this->delete($activityId);
		}
		elseif (!$newBindings->isEquals($previousBindings))
		{
			$this->update($activityId, [
				'BINDINGS' => $newBindings->toArray('OWNER_ID', 'OWNER_TYPE_ID'),
			]);

			$ids = $this->getIdsToDelete($previousBindings->getDiff($newBindings), $taskId);
			foreach ($ids as $id)
			{
				TimelineBindingTable::deleteByOwner($id);
			}
		}
	}

	public function update(int $activityId, array $fields): void
	{
		CCrmActivity::Update($activityId, $fields, false, true, self::UPDATE_OPTIONS);
	}

	public function getIdsToDelete(Bindings $toRemove, int $taskId): array
	{
		$timelineEntryIdsByTaskId = [];
		foreach ($toRemove as $identifier)
		{
			$query = TimelineTable::query();
			$query
				->setSelect(['ID', 'BINDINGS'])
				->where('BINDINGS.ENTITY_ID', $identifier->getEntityId())
				->where('BINDINGS.ENTITY_TYPE_ID', $identifier->getEntityTypeId())
				->where('TYPE_ID', TimelineType::TASK)
				->where('SOURCE_ID', $taskId)
			;
			$timelineEntryIdsByTaskId = $query->exec()->fetchCollection()->getIdList();
		}

		return $timelineEntryIdsByTaskId;
	}

	public static function deleteAssociatedEntity($entityId, array $activity, array $options = []): Result
	{
		$result = new Result();
		if (isset($options['SKIP_TASKS']) && $options['SKIP_TASKS'] === true)
		{
			return $result;
		}

		$taskId = (int)$entityId;
		$responsibleId = (int)($activity['RESPONSIBLE_ID'] ?? null);
		$activityId = (int)($activity['ID'] ?? null);
		if ($taskId <= 0 || $responsibleId <=0 || $activityId <=0)
		{
			$result->addError(new Error('Wrong task or responsible or activity id.'));
			return $result;
		}

		TimelineEntry::deleteByAssociatedEntity(CCrmOwnerType::Activity, $activity['ID']);

		$bindings = $activity['BINDINGS'] ?? [];
		$commentProvider = new Comment();
		foreach ($bindings as $item)
		{
			$slaveActivity = $commentProvider->find(
				$taskId,
				new ItemIdentifier($item['OWNER_TYPE_ID'], $item['OWNER_ID'])
			);

			if (!is_null($slaveActivity))
			{
				$commentProvider->delete($slaveActivity->getId());
			}
		}

		return $result;
	}

	public static function rebindAssociatedEntity($entityId, $oldOwnerTypeId, $newEntityTypeId, $oldOwnerId, $newOwnerId): Result
	{
		$result = new Result();
		$taskId = (int)$entityId;
		if ($taskId <= 0)
		{
			$result->addError(new Error('Wrong task id.'));
			return $result;
		}

		$task = TaskObject::getObject($taskId, true);
		if(is_null($task))
		{
			$result->addError(new Error('No task data.'));
			return $result;
		}

		try
		{
			$entityBindings = $task->getCrmFields();
			$entityIndex = -1;
			$length = count($entityBindings);

			for($i = 0; $i < $length; ++$i)
			{
				$entityInfo = CCrmOwnerType::ParseEntitySlug($entityBindings[$i]);
				if(
					is_array($entityInfo)
					&& $entityInfo['ENTITY_TYPE_ID'] === $oldOwnerTypeId
					&& $entityInfo['ENTITY_ID'] === $oldOwnerId
				)
				{
					$entityIndex = $i;
					break;
				}
			}

			if($entityIndex >= 0)
			{
				$entityBindings[$entityIndex] = CCrmOwnerTypeAbbr::ResolveByTypeID($newEntityTypeId).'_'.$newOwnerId;
				$handler = TaskHandler::getHandler();
				$handler->update($taskId, [
					self::TASK_CRM_FIELD => $entityBindings
				]);
			}
		}
		catch (\Exception $exception)
		{
			$result->addError(new Error($exception->getMessage()));
		}

		return $result;
	}

	public static function processRestorationFromRecycleBin(array $activityFields, array $params = null): Result
	{
		$result = new Result();
		$taskId = (int)($activityFields['ASSOCIATED_ENTITY_ID'] ?? null);
		if ($taskId <= 0)
		{
			return $result;
		}

		$bindings = $activityFields['BINDINGS'] ?? [];
		if (empty($bindings))
		{
			return $result;
		}

		$task = TaskObject::getObject($taskId);
		if (is_null($task))
		{
			return $result;
		}

		try
		{
			$crmFields = array_unique(array_merge($task->getCrmFields(), self::prepareBindingsToTask($bindings)));

			TaskHandler::getHandler()->update($taskId,[
				self::TASK_CRM_FIELD => $crmFields
			]);

			$activity = self::prepareQuery($taskId)->exec()->fetchObject();

			$provider = new self();
			$provider->deleteLogEntry((int)$activity?->getId(), $taskId);
			$provider->update((int)$activity?->getId(), ['STORAGE_ELEMENT_IDS' => $activityFields['STORAGE_ELEMENT_IDS']]);

			$result->setData(['entityId' => (int)$activity?->getId()]);
		}
		catch (\Exception $exception)
		{
			$result->addError(new Error($exception->getMessage()));
		}

		return $result;
	}

	public static function processMovingToRecycleBin(array $activityFields, array $params = null): Result
	{
		$result = new Result();

		$taskId = (int)($activityFields['ASSOCIATED_ENTITY_ID'] ?? null);
		if ($taskId <= 0)
		{
			$result->addError(new Error('Wrong task id.'));
			return $result;
		}

		try
		{
			TaskHandler::getHandler()->update(
				$taskId,
				[
					self::TASK_CRM_FIELD => []
				]
			);
		}
		catch (\Exception $exception)
		{
			$result->addError(new Error($exception->getMessage()));
			return $result;
		}

		$result->setData(['isDeleted' => true]);

		return $result;
	}

	private static function prepareBindingsToTask(array $bindings): array
	{
		$crmTaskFields = [];
		foreach($bindings as $binding)
		{
			$entityTypeId = (int)($binding['OWNER_TYPE_ID'] ?? null);
			$entityId = (int)($binding['OWNER_ID'] ?? null);

			if($entityId <= 0 || !CCrmOwnerType::IsDefined($entityTypeId))
			{
				continue;
			}

			$type = CCrmOwnerTypeAbbr::ResolveByTypeID($entityTypeId);
			if ($type === \CCrmOwnerTypeAbbr::Undefined)
			{
				continue;
			}
			$crmTaskFields[] = $type . '_' . $entityId;
		}

		return $crmTaskFields;
	}

	public function getEditAction(int $activityId, int $userId = 0): string
	{
		if (!Loader::includeModule('tasks'))
		{
			return '';
		}

		$query = ActivityTable::query();
		$query
			->setSelect(['ID', 'ASSOCIATED_ENTITY_ID'])
			->where('ID', $activityId)
		;

		$activity = $query->exec()->fetchObject();
		if (is_null($activity))
		{
			return '';
		}

		$taskId = $activity->getAssociatedEntityId();
		if (is_null($taskId))
		{
			return '';
		}

		$factory = TaskSliderFactory::getFactory();
		if (is_null($factory))
		{
			return '';
		}

		$factory
			->setAction($factory::EDIT_ACTION)
			->skipEvents()
		;
		$slider = $factory->createEntitySlider(
			$taskId,
			$factory::TASK,
			$userId,
			$factory::PERSONAL_CONTEXT
		);

		return $slider->getJs();
	}

	public static function isTask(): bool
	{
		return true;
	}

	public static function isActivityEditable(array $activity = [], int $userId = 0): bool
	{
		$taskId = (int)($activity['ASSOCIATED_ENTITY_ID'] ?? null);
		if ($taskId <= 0)
		{
			return false;
		}

		return TaskAccessController::canEdit($taskId, $userId);
	}

	public static function checkCompletePermission($entityId, array $activity, $userId): ?bool
	{
		$taskId = (int)$entityId;
		if ($taskId <= 0)
		{
			return true;
		}

		$task = TaskObject::getObject($taskId);
		if (is_null($task))
		{
			return true;
		}

		if ($task->getZombie())
		{
			return true;
		}

		$status = (int)$task->getStatus();
		if ($status === TaskActivityStatus::TASKS_STATE_COMPLETED)
		{
			return true;
		}

		if (!TaskAccessController::canCompleteResult($taskId, $userId))
		{
			$uri = new Uri(TaskPathMaker::getPathMaker($taskId, $userId)->makeEntityPath());
			$uri->addParams(['RID' => 0]);

			$message = Loc::getMessage('TASKS_TASK_ERROR_REQUIRE_RESULT', [
				'#TASK_URL#' => $uri->getUri(),
			]);
			self::setCompletionDeniedError($message);

			return false;
		}

		return TaskAccessController::canComplete($taskId, $userId);
	}

	public static function getKey(): string
	{
		return self::getId() . '.' . self::getProviderTypeId() . '.*';
	}

	public function deleteLogEntry(int $activityId, int $taskId): int
	{
		$logEntryId = $this->getCompletedActivityEntryId($activityId, $taskId);
		if ($logEntryId > 0)
		{
			TimelineEntry::delete($logEntryId);
			return $logEntryId;
		}

		return 0;
	}

	public static function onAfterUpdate(
		int $id,
		array $changedFields,
		array $oldFields,
		array $newFields,
		array $params = null
	)
	{
		$taskId = $newFields['ASSOCIATED_ENTITY_ID'] ?? 0;
		if ($taskId <= 0)
		{
			return;
		}

		$task = TaskObject::getObject($taskId);
		if (is_null($task))
		{
			return;
		}

		$bindings = $newFields['BINDINGS'] ?? [];
		if (empty($bindings))
		{
			return;
		}
		$taskCrmFields = $task->getCrmFields();
		$crmFields = array_unique(array_merge($taskCrmFields, self::prepareBindingsToTask($bindings)));
		if (
			empty(array_diff($crmFields, $taskCrmFields))
			&& empty(array_diff($taskCrmFields, $crmFields))
		)
		{
			return;
		}

		TaskHandler::getHandler()->update($taskId,[
			self::TASK_CRM_FIELD => $crmFields
		]);
	}

	/**
	 * There are two kind of task. Old with type = 3 and new with provider_id = CRM_TASKS_TASK
	 * We have to query both when selected 'Task' in the filter.
	 */
	public static function transformTaskInFilter(
		array &$filter,
		string $typeFieldName = 'TYPE_ID',
		bool $allTaskBased = false
	): void
	{
		if (
			is_array($filter[$typeFieldName] ?? null)
			&& in_array(\CCrmActivityType::Task, $filter[$typeFieldName])
		)
		{
			$filter[$typeFieldName][] = $allTaskBased
				? self::getId() . '.*.*'
				: Task::getKey();
		}
	}
}

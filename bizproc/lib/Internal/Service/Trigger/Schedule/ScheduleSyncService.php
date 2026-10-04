<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Trigger\Schedule;

use Bitrix\Bizproc\BaseType\Value;
use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Service\ManagedAgentResourceRegistry;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType;
use Bitrix\Bizproc\Internal\Entity\Trigger\ScheduledData;
use Bitrix\Bizproc\Internal\Entity\Trigger\TriggerSchedule;
use Bitrix\Bizproc\Internal\Entity\Trigger\TriggerScheduleCollection;
use Bitrix\Bizproc\Internal\Repository\TriggerScheduleRepository\TriggerScheduleRepository;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Recurr\Exception\InvalidArgument;
use Recurr\Exception\InvalidRRule;
use Recurr\Exception\InvalidWeekday;

class ScheduleSyncService
{
	public const TRIGGER_TYPE = 'ScheduledTrigger';
	private const TRIGGER_NAME_COLUMN = 'triggerName';
	private const PROPERTIES_COLUMN = 'properties';

	/**
	 * Phrase of the refusal a person is shown when the schedule of a managed AI agent cannot be saved right now.
	 */
	private const REFUSAL_MESSAGE_ID = 'BIZPROC_SCHEDULE_SYNC_MANAGED_AGENT_BUSY';

	public function __construct(
		private readonly ScheduleCalculator $calculator,
		private readonly TriggerScheduleRepository $repository,
		private readonly Searcher $searcher,
		private readonly ScheduledTriggerAgentSyncService $agentSyncService,
	)
	{
	}

	/**
	 * @param int $templateId
	 * @param array|null $triggers
	 * @param bool $active
	 *
	 * @throws ArgumentException
	 * @throws InvalidArgument
	 * @throws InvalidRRule
	 * @throws InvalidWeekday
	 * @throws ObjectPropertyException
	 * @throws PersistenceException
	 * @throws SystemException
	 * @throws \CBPArgumentOutOfRangeException
	 */
	public function syncByTemplate(int $templateId, ?array $triggers, bool $active = false): void
	{
		if (!$this->searcher->includeActivityFile(strtolower(self::TRIGGER_TYPE)))
		{
			return;
		}

		if (!$active || empty($triggers))
		{
			$this->repository->deleteByTemplate($templateId);
			$this->agentSyncService->syncAgentSchedule();

			return;
		}

		$templateConstants = $this->getTemplateConstants($templateId);
		$scheduleTriggers = $this->filterScheduleTriggers($triggers);
		$triggerNames = array_column($scheduleTriggers, self::TRIGGER_NAME_COLUMN);

		$existingSchedules = $this->indexSchedulesByTriggerName(
			$this->repository->getByTemplate($templateId)
		);
		$this->repository->deleteByTemplate($templateId, $triggerNames);

		$this->saveSchedules($templateId, $scheduleTriggers, $templateConstants, $existingSchedules);

		$this->agentSyncService->syncAgentSchedule();
	}

	/**
	 * Stores the schedules of the template and, when the template is a managed system AI agent copy, registers
	 * every stored row as a resource of its instance in one transaction: a schedule that outlived its instance
	 * would keep starting workflows, so either all of the stored rows appear together with their ownership or
	 * none of them does.
	 *
	 * The rows the template no longer describes are removed by the caller before this transaction is opened and
	 * are not brought back by its rollback. That is deliberate: a schedule that is gone starts nothing and
	 * endangers no invariant, while a restored one would keep starting workflows of a copy whose removal has
	 * already begun - which is exactly what the transaction is here to prevent.
	 *
	 * The rows are built before anything is taken, so a template that stores nothing - because it describes no
	 * schedule at all, or because none of its triggers describes one - opens neither the transaction nor the lock.
	 *
	 * The logical lock of a managed copy is taken before that transaction is opened, so that the whole batch is
	 * stored and registered under one lock instead of asking for it again on every row. It is not a promise that
	 * no row lock is held while the lock is awaited: the production path runs inside the transaction of the
	 * caller of the ORM events of a template, which may hold row locks of its own by then. For a template that
	 * is not managed there is nothing to lock and the registration is an immediate no-op.
	 *
	 * @param array<string, TriggerSchedule> $existingSchedules
	 * @throws SystemException when the creation barrier of the registry refuses a schedule
	 */
	private function saveSchedules(
		int $templateId,
		array $scheduleTriggers,
		array $templateConstants,
		array $existingSchedules,
	): void
	{
		$schedules = $this->buildSchedules($templateId, $scheduleTriggers, $templateConstants, $existingSchedules);
		if ($schedules === [])
		{
			return;
		}

		$registry = self::getRegistry();
		$lockedIdentity = $registry?->acquireTemplateLock($templateId);

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			foreach ($schedules as $schedule)
			{
				$this->saveSchedule($templateId, $schedule);
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			// A rollback of a nested transaction takes the rows back to the savepoint and throws afterwards, so the
			// throw of the rollback is swallowed here: the caller has to learn the cause of the failure and not the
			// way the rows were taken back.
			try
			{
				$connection->rollbackTransaction();
			}
			catch (\Throwable)
			{
			}

			throw $exception;
		}
		finally
		{
			if ($lockedIdentity !== null)
			{
				$registry->releaseTemplateLock($lockedIdentity);
			}
		}
	}

	/**
	 * Rows the template describes, in the order of its triggers. A trigger whose properties describe no schedule
	 * is dropped here, so the caller learns before it takes anything whether there is something to store at all.
	 *
	 * Nothing in the building reads or writes the schedule table, therefore an entity built ahead of the
	 * transaction is the same entity the loop inside it would have built.
	 *
	 * @param array<string, TriggerSchedule> $existingSchedules
	 * @return list<TriggerSchedule>
	 */
	private function buildSchedules(
		int $templateId,
		array $scheduleTriggers,
		array $templateConstants,
		array $existingSchedules,
	): array
	{
		$schedules = [];

		foreach ($scheduleTriggers as $trigger)
		{
			$scheduleData = $this->buildScheduleData($trigger[self::PROPERTIES_COLUMN], $templateConstants);
			if ($scheduleData === null)
			{
				continue;
			}

			$triggerName = $trigger[self::TRIGGER_NAME_COLUMN];
			$nextRunAt = $this->calculator->calculateNextRunAt($scheduleData->toArray());
			$entity = (new TriggerSchedule())
				->setTemplateId($templateId)
				->setTriggerName($triggerName)
				->setScheduleType($scheduleData->frequency->value)
				->setScheduleData($scheduleData)
				->setNextRunAt($nextRunAt)
			;

			if (isset($existingSchedules[$triggerName]))
			{
				$existing = $existingSchedules[$triggerName];
				$entity
					->setId($existing->getId())
					->setLastRunAt($existing->getLastRunAt())
				;
			}

			$schedules[] = $entity;
		}

		return $schedules;
	}

	/**
	 * Saves one schedule row and hands it to the creation barrier of the registry, inside the transaction the
	 * whole cycle of the template runs in.
	 *
	 * The two answers of the barrier are told apart here, because this runs on the save path of every template of
	 * the portal: a copy whose removal has begun and an identity another operation of the same agent holds are a
	 * refusal that is over on its own, so it leaves as a message a person can be shown and a save that may be
	 * repeated. Everything else - an unreadable registry, a payload the schema refuses - stays a failure of the
	 * storage and keeps its technical exception.
	 *
	 * @throws SystemException when the barrier refuses the schedule of a managed copy
	 * @throws PersistenceException when the barrier could not answer at all
	 */
	private function saveSchedule(int $templateId, TriggerSchedule $schedule): void
	{
		$saved = $this->repository->save($schedule);

		$registration = $this->registerSchedule($templateId, (int)$saved->getId());
		if ($registration->isSuccess())
		{
			return;
		}

		if (self::isRefusedByOperation($registration))
		{
			throw new SystemException((string)Loc::getMessage(self::REFUSAL_MESSAGE_ID));
		}

		throw new PersistenceException(
			'Unable to register a managed agent schedule: ' . self::describeErrorCodes($registration),
		);
	}

	/**
	 * Whether the barrier refused the schedule instead of failing to answer about it.
	 *
	 * Both codes name an operation of the very same agent: its removal refuses every new resource, and its lock
	 * is held while it runs. Neither of them says anything about the template the person is saving, therefore
	 * neither is reported as a defect.
	 */
	private static function isRefusedByOperation(Result $result): bool
	{
		$error = $result->getErrors()[0] ?? null;
		$code = $error === null ? '' : (string)$error->getCode();

		return
			$code === ManagedAgentResourceRegistry::ERROR_CREATION_REFUSED
			|| $code === ManagedAgentResourceRegistry::ERROR_LOCK_CONFLICT
		;
	}

	/**
	 * Result of the registry barrier, or an empty success while the registry is not in the locator: without it no
	 * managed instance can own anything, so there is nothing to refuse.
	 */
	private function registerSchedule(int $templateId, int $scheduleId): Result
	{
		$registry = self::getRegistry();
		if ($registry === null)
		{
			return new Result();
		}

		return $registry->register($templateId, ManagedAgentResourceType::Schedule, (string)$scheduleId);
	}

	/**
	 * The shared registry of the request, or null while the service is not in the locator.
	 */
	private static function getRegistry(): ?ManagedAgentResourceRegistry
	{
		$locator = ServiceLocator::getInstance();

		return $locator->has(ManagedAgentResourceRegistry::SERVICE_CODE)
			? $locator->get(ManagedAgentResourceRegistry::SERVICE_CODE)
			: null
		;
	}

	/**
	 * Stable codes of the refusal, which are constants of the registry and carry no input values.
	 */
	private static function describeErrorCodes(Result $result): string
	{
		$codes = [];
		foreach ($result->getErrors() as $error)
		{
			$codes[] = (string)$error->getCode();
		}

		return implode(', ', $codes);
	}

	private function buildScheduleData(array $properties, array $templateConstants = []): ?ScheduledData
	{
		if ($templateConstants)
		{
			$properties = $this->resolveProperties($properties, $templateConstants);
		}

		$type = ScheduleType::tryFrom((string)($properties[\CBPScheduledTrigger::PROPERTY_SCHEDULE_TYPE] ?? ''));
		if ($type === null)
		{
			return null;
		}

		$runAt = $this->extractRunAtValue($properties[\CBPScheduledTrigger::PROPERTY_RUN_AT] ?? null);
		if ($runAt === null)
		{
			return null;
		}

		$timezone = $this->formatOffsetAsTimeZone($runAt->getOffset());
		$startAt = $this->formatStartAt($runAt, $timezone);

		if ($startAt === null)
		{
			return null;
		}

		$interval = $this->normalizeInterval($properties[\CBPScheduledTrigger::PROPERTY_INTERVAL] ?? 1);
		$byWeekDay = $this->normalizeWeekDays($properties[\CBPScheduledTrigger::PROPERTY_WEEK_DAYS] ?? []);
		$byMonthDay = !empty($properties[\CBPScheduledTrigger::PROPERTY_MONTH_DAY]) ? (int)$properties[\CBPScheduledTrigger::PROPERTY_MONTH_DAY]
			: null;

		$byMonth = !empty($properties[\CBPScheduledTrigger::PROPERTY_YEAR_MONTH]) ? (int)$properties[\CBPScheduledTrigger::PROPERTY_YEAR_MONTH]
			: null;

		return new ScheduledData(
			startAt: $startAt,
			timezone: $timezone,
			frequency: $type,
			interval: $interval,
			byMonth: $byMonth,
			byMonthDay: $byMonthDay,
			byWeekDay: $byWeekDay,
		);
	}

	private function extractRunAtValue(null|Value\Time|Value\DateTime|string $value): ?Value\DateTime
	{
		if ($value instanceof Value\DateTime)
		{
			return $value;
		}

		if (is_string($value) && $value !== '')
		{
			$dateTime = new Value\DateTime($value);

			if ($dateTime->getTimestamp() !== null)
			{
				return $dateTime;
			}

			$time = new Value\Time($value);

			return new Value\DateTime($time->getTimestamp(), $time->getOffset());
		}

		return null;
	}

	private function resolveProperties(array $properties, array $templateConstants): array
	{
		return array_map(
			fn (mixed $value) => $this->resolveValue($value, $templateConstants),
			$properties
		);
	}

	private function resolveValue(mixed $value, array $templateConstants): mixed
	{
		if (is_array($value))
		{
			return array_map(
				fn (mixed $item) => $this->resolveValue($item, $templateConstants),
				$value
			);
		}

		if (!is_string($value))
		{
			return $value;
		}

		$expression = \CBPActivity::parseExpression($value);
		if (!$expression)
		{
			return $value;
		}

		if ($expression['object'] !== 'Constant')
		{
			return null;
		}

		$constantId = $expression['field'] ?? null;
		if (!$constantId || !isset($templateConstants[$constantId]))
		{
			return null;
		}

		return $templateConstants[$constantId]['Default'] ?? null;
	}

	/**
	 * @throws \CBPArgumentOutOfRangeException
	 */
	private function getTemplateConstants(int $templateId): array
	{
		if ($templateId <= 0)
		{
			return [];
		}

		$constants = \CBPWorkflowTemplateLoader::getTemplateConstants($templateId);

		return is_array($constants) ? $constants : [];
	}

	private function formatOffsetAsTimeZone(int $offset): string
	{
		$sign = $offset >= 0 ? '+' : '-';
		$abs = abs($offset);
		$hours = (int)floor($abs / 3600);
		$minutes = (int)floor(($abs % 3600) / 60);

		return sprintf('%s%02d:%02d', $sign, $hours, $minutes);
	}

	private function formatStartAt(Value\DateTime $runAt, string $timezone): ?string
	{
		try
		{
			$tz = new \DateTimeZone($timezone);
			$userLocal = (new \DateTimeImmutable('@' . $runAt->getTimestamp()))->setTimezone($tz);

			$now = new \DateTimeImmutable('now', $tz);
			$local = $now->setTime((int)$userLocal->format('H'), (int)$userLocal->format('i'));

			return $local->format('Y-m-d H:i:s');
		}
		catch (\Exception)
		{
			return null;
		}
	}

	private function normalizeWeekDays(mixed $value): array
	{
		$raw = \CBPHelper::flatten($value ?? []);

		$result = [];
		foreach ($raw as $day)
		{
			$weekday = RecurrWeekday::fromNumericDay((int)$day);
			if ($weekday !== null)
			{
				$result[] = $weekday->value;
			}
		}

		return array_values(array_unique($result));
	}

	private function normalizeInterval(mixed $value): int
	{
		$interval = (int)$value;
		if ($interval < 1)
		{
			return 1;
		}
		if ($interval > 12)
		{
			return 12;
		}

		return $interval;
	}

	private function filterScheduleTriggers(array $triggers): array
	{
		$result = [];
		foreach ($triggers as $trigger)
		{
			if (($trigger['TRIGGER_TYPE'] ?? '') !== self::TRIGGER_TYPE)
			{
				continue;
			}

			$applyRules = $trigger['APPLY_RULES'] ?? [];
			$properties = $applyRules['Properties'] ?? [];
			$triggerName = (string)($applyRules['TriggerName'] ?? $trigger['TRIGGER_NAME'] ?? '');
			if ($triggerName === '')
			{
				continue;
			}

			$result[] = [
				self::TRIGGER_NAME_COLUMN => $triggerName,
				self::PROPERTIES_COLUMN => $properties,
			];
		}

		return $result;
	}

	/**
	 * @param TriggerScheduleCollection $schedules
	 *
	 * @return array<string, TriggerSchedule>
	 */
	private function indexSchedulesByTriggerName(TriggerScheduleCollection $schedules): array
	{
		$indexed = [];
		foreach ($schedules as $schedule)
		{
			$triggerName = $schedule->getTriggerName();
			if ($triggerName !== '')
			{
				$indexed[$triggerName] = $schedule;
			}
		}

		return $indexed;
	}
}

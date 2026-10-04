<?php

namespace Bitrix\Crm\Recurring\Entity;

use Bitrix\Crm\AutomatedSolution\CapabilityAccessChecker;
use Bitrix\Crm\Item;
use Bitrix\Crm\Model\Dynamic\RecurringTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

final class DynamicAgentSelector
{
	public const KEY_ITEMS = 'ITEMS';
	public const KEY_HAS_CANDIDATES = 'HAS_CANDIDATES';
	public const KEY_RECURRING_FIELDS = 'RECURRING_FIELDS';
	public const KEY_TEMPLATE_ITEM = 'TEMPLATE_ITEM';
	public const KEY_EXECUTION_CONTEXT = 'EXECUTION_CONTEXT';
	public const KEY_NEXT_CURSOR_DATE = 'NEXT_CURSOR_DATE';
	public const KEY_NEXT_CURSOR_ID = 'NEXT_CURSOR_ID';

	private const PAGE_SIZE = 100;
	private const MAX_CALENDAR_SKEW_DAYS = 2;

	private ?\Closure $pageLoader;
	private ?\Closure $factoryProvider;
	private ?\Closure $userLoader;
	private ?\Closure $timezoneEnabledResolver;
	private ?\Closure $offsetCalculator;

	public function __construct(
		?\Closure $pageLoader = null,
		?\Closure $factoryProvider = null,
		?\Closure $userLoader = null,
		?\Closure $timezoneEnabledResolver = null,
		?\Closure $offsetCalculator = null,
	)
	{
		$this->pageLoader = $pageLoader;
		$this->factoryProvider = $factoryProvider;
		$this->userLoader = $userLoader;
		$this->timezoneEnabledResolver = $timezoneEnabledResolver;
		$this->offsetCalculator = $offsetCalculator;
	}

	public function select(
		int $workLimit,
		?DateTime $serverMoment = null,
		?Date $cursorDate = null,
		int $cursorId = 0,
	): array
	{
		$serverMoment = $serverMoment === null ? new DateTime() : clone $serverMoment;
		$serverDate = new Date($serverMoment->format('Y-m-d'), 'Y-m-d');
		$upperDate = (clone $serverDate)->add('+' . self::MAX_CALENDAR_SKEW_DAYS . ' days');
		$timezoneEnabled = $this->isTimezoneEnabled();
		$hasWorkLimit = $workLimit > 0;
		if (!$hasWorkLimit)
		{
			$cursorDate = null;
			$cursorId = 0;
		}

		$readyItems = [];
		$hasCandidates = false;
		$hasWrapped = false;
		$nextCursorDate = $cursorDate === null ? null : clone $cursorDate;
		$nextCursorId = $cursorId;

		while (!$hasWorkLimit || count($readyItems) < $workLimit)
		{
			$rows = $this->loadPage($upperDate, $cursorDate, $cursorId);
			if (empty($rows))
			{
				if ($hasWorkLimit && $cursorDate !== null && !$hasWrapped)
				{
					$cursorDate = null;
					$cursorId = 0;
					$nextCursorDate = null;
					$nextCursorId = 0;
					$hasWrapped = true;

					continue;
				}

				break;
			}

			$hasCandidates = true;
			$lightTemplates = $this->loadTemplates($rows, true);
			$userIds = $this->collectUserIds($lightTemplates);
			$users = empty($userIds) ? [] : $this->loadUsers($userIds);
			$selectedCandidates = [];

			foreach ($rows as $row)
			{
				$nextExecution = $row['NEXT_EXECUTION'] ?? null;
				if ($nextExecution instanceof Date)
				{
					$nextCursorDate = clone $nextExecution;
					$nextCursorId = (int)($row['ID'] ?? 0);
				}

				$entityTypeId = (int)($row['ENTITY_TYPE_ID'] ?? 0);
				$itemId = (int)($row['ITEM_ID'] ?? 0);
				$template = $lightTemplates[$entityTypeId][$itemId] ?? null;
				if (!$template instanceof Item)
				{
					continue;
				}

				$assignedByIds = $this->normalizeUserIds($template->get(Item::FIELD_NAME_ASSIGNED));
				$assignedDates = $this->resolveAssignedDates(
					$assignedByIds,
					$users,
					$serverMoment,
					$timezoneEnabled,
				);
				$commonDate = $this->getMinimumDate($assignedDates);
				if (!$this->isReady($row, $assignedDates, $commonDate))
				{
					continue;
				}

				$selectedCandidates[] = [
					'row' => $row,
					'context' => new DynamicExecutionContext(
						$serverMoment,
						$commonDate,
						(int)($row['CREATED_BY_ID'] ?? 0),
					),
					'assignedByIds' => $assignedByIds,
				];

				if ($hasWorkLimit && count($selectedCandidates) === $workLimit)
				{
					break;
				}
			}

			$selectedRows = array_column($selectedCandidates, 'row');
			$templates = $this->loadTemplates($selectedRows, false);
			foreach ($selectedCandidates as $candidate)
			{
				$row = $candidate['row'];
				$entityTypeId = (int)($row['ENTITY_TYPE_ID'] ?? 0);
				$itemId = (int)($row['ITEM_ID'] ?? 0);
				$template = $templates[$entityTypeId][$itemId] ?? null;
				if (!$template instanceof Item)
				{
					continue;
				}
				if (
					$this->normalizeUserIds($template->get(Item::FIELD_NAME_ASSIGNED))
					!== $candidate['assignedByIds']
				)
				{
					continue;
				}

				$readyItems[] = [
					self::KEY_RECURRING_FIELDS => $row,
					self::KEY_TEMPLATE_ITEM => $template,
					self::KEY_EXECUTION_CONTEXT => $candidate['context'],
				];
			}

			$cursorDate = $nextCursorDate === null ? null : clone $nextCursorDate;
			$cursorId = $nextCursorId;
			if ($hasWorkLimit)
			{
				break;
			}
		}

		return [
			self::KEY_ITEMS => $readyItems,
			self::KEY_HAS_CANDIDATES => $hasCandidates,
			self::KEY_NEXT_CURSOR_DATE => $nextCursorDate?->format('Y-m-d'),
			self::KEY_NEXT_CURSOR_ID => $nextCursorId,
		];
	}

	private function loadPage(Date $upperDate, ?Date $cursorDate, int $cursorId): array
	{
		if ($this->pageLoader !== null)
		{
			return ($this->pageLoader)($upperDate, $cursorDate, $cursorId, self::PAGE_SIZE);
		}

		$filter = Query::filter()
			->where('ACTIVE', 'Y')
			->where('NEXT_EXECUTION', '<=', $upperDate)
		;
		if ($cursorDate !== null)
		{
			$cursorFilter = Query::filter()
				->logic('or')
				->where('NEXT_EXECUTION', '>', $cursorDate)
				->where(
					Query::filter()
						->where('NEXT_EXECUTION', $cursorDate)
						->where('ID', '>', $cursorId)
					,
				)
			;
			$filter->where($cursorFilter);
		}

		return RecurringTable::query()
			->setSelect(['*'])
			->where($filter)
			->setOrder([
				'NEXT_EXECUTION' => 'ASC',
				'ID' => 'ASC',
			])
			->setLimit(self::PAGE_SIZE)
			->fetchAll()
		;
	}

	private function loadTemplates(array $rows, bool $lightweight): array
	{
		$itemIdsByType = [];
		foreach ($rows as $row)
		{
			$entityTypeId = (int)($row['ENTITY_TYPE_ID'] ?? 0);
			$itemId = (int)($row['ITEM_ID'] ?? 0);
			if ($entityTypeId > 0 && $itemId > 0)
			{
				$itemIdsByType[$entityTypeId][$itemId] = $itemId;
			}
		}

		$templates = [];
		foreach ($itemIdsByType as $entityTypeId => $itemIds)
		{
			$factory = $this->getFactory($entityTypeId);
			if (!$factory || !$factory->isRecurringEnabled())
			{
				continue;
			}

			$parameters = [
				'filter' => [
					'@ID' => array_values($itemIds),
					'=IS_RECURRING' => 'Y',
				],
			];
			if ($lightweight)
			{
				$parameters['select'] = [Item::FIELD_NAME_ID, Item::FIELD_NAME_ASSIGNED];
			}

			$items = $factory->getItems($parameters);
			foreach ($items as $item)
			{
				$templates[$entityTypeId][(int)$item->get(Item::FIELD_NAME_ID)] = $item;
			}
		}

		return $templates;
	}

	private function getFactory(int $entityTypeId): ?Factory
	{
		if ($this->factoryProvider !== null)
		{
			return ($this->factoryProvider)($entityTypeId);
		}

		if (CapabilityAccessChecker::getInstance()->isLockedEntityType($entityTypeId))
		{
			return null;
		}

		return Container::getInstance()->getFactory($entityTypeId);
	}

	private function collectUserIds(array $templates): array
	{
		$userIds = [];
		foreach ($templates as $typeTemplates)
		{
			foreach ($typeTemplates as $template)
			{
				foreach ($this->normalizeUserIds($template->get(Item::FIELD_NAME_ASSIGNED)) as $userId)
				{
					$userIds[$userId] = $userId;
				}
			}
		}

		return array_values($userIds);
	}

	private function normalizeUserIds(mixed $assignedById): array
	{
		$userIds = [];
		foreach ((array)$assignedById as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$userIds[$userId] = $userId;
			}
		}

		return array_values($userIds);
	}

	private function loadUsers(array $userIds): array
	{
		if ($this->userLoader !== null)
		{
			return ($this->userLoader)($userIds);
		}

		$rows = UserTable::query()
			->setSelect(['ID', 'TIME_ZONE', 'TIME_ZONE_OFFSET'])
			->whereIn('ID', $userIds)
			->fetchAll()
		;
		$users = [];
		foreach ($rows as $row)
		{
			$users[(int)$row['ID']] = $row;
		}

		return $users;
	}

	private function resolveAssignedDates(
		array $assignedByIds,
		array $users,
		DateTime $serverMoment,
		bool $timezoneEnabled,
	): array
	{
		$dates = [];
		$serverDate = new Date($serverMoment->format('Y-m-d'), 'Y-m-d');
		foreach ($assignedByIds as $userId)
		{
			if (!isset($users[$userId]))
			{
				$dates[$userId] = clone $serverDate;

				continue;
			}

			$user = $users[$userId];
			$offset = 0;
			if ($timezoneEnabled)
			{
				$timeZone = trim((string)($user['TIME_ZONE'] ?? ''));
				$offset = $timeZone !== ''
					? $this->calculateOffset($timeZone, $serverMoment)
					: (int)($user['TIME_ZONE_OFFSET'] ?? 0);
			}

			$localMoment = clone $serverMoment;
			if ($offset !== 0)
			{
				$localMoment->add(($offset > 0 ? '+' : '') . $offset . ' seconds');
			}
			$dates[$userId] = new Date($localMoment->format('Y-m-d'), 'Y-m-d');
		}
		if (empty($dates))
		{
			$dates[] = $serverDate;
		}

		return $dates;
	}

	private function isTimezoneEnabled(): bool
	{
		if ($this->timezoneEnabledResolver !== null)
		{
			return (bool)($this->timezoneEnabledResolver)();
		}

		return \CTimeZone::OptionEnabled();
	}

	private function calculateOffset(string $timeZone, DateTime $serverMoment): int
	{
		if ($this->offsetCalculator !== null)
		{
			return (int)($this->offsetCalculator)($timeZone, $serverMoment);
		}

		return \CTimeZone::calculateOffset($timeZone, $serverMoment);
	}

	private function getMinimumDate(array $dates): Date
	{
		$minimumDate = null;
		foreach ($dates as $date)
		{
			if ($minimumDate === null || $date->getTimestamp() < $minimumDate->getTimestamp())
			{
				$minimumDate = $date;
			}
		}

		return clone $minimumDate;
	}

	private function isReady(array $row, array $assignedDates, Date $commonDate): bool
	{
		$nextExecution = $row['NEXT_EXECUTION'] ?? null;
		if (!$nextExecution instanceof Date)
		{
			return false;
		}

		foreach ($assignedDates as $assignedDate)
		{
			if ($nextExecution->getTimestamp() > $assignedDate->getTimestamp())
			{
				return false;
			}
		}

		$lastExecution = $row['LAST_EXECUTION'] ?? null;

		return !$lastExecution instanceof Date
			|| $lastExecution->getTimestamp() < $commonDate->getTimestamp();
	}
}

<?php

namespace Bitrix\Crm\RepeatSale\Service\Handler;

use Bitrix\Crm\Item;
use Bitrix\Crm\RepeatSale\AssignmentStrategies;
use Bitrix\Crm\RepeatSale\Segment\AssignmentType;
use Bitrix\Crm\RepeatSale\Segment\Collector\Factory;
use Bitrix\Crm\RepeatSale\Segment\Data\SegmentDataInterface;
use Bitrix\Crm\RepeatSale\Segment\SegmentCode;
use Bitrix\Crm\RepeatSale\Service\Context;
use Bitrix\Crm\RepeatSale\Service\Operation;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Error;
use Bitrix\Main\UserTable;

abstract class BaseHandler
{
	protected int $entityTypeId = 0;
	protected ?int $lastEntityId = null;
	protected ?int $lastAssignmentId = null;
	protected bool $isOnlyCalc = false;
	protected int $limit = 50;
	protected int $offset = 0;
	protected int $minimumDaysAfterLastClosedEntity = 0;

	private ?array $activeFallbackUserIds = null;

	public function __construct(
		protected readonly string $segmentCode,
		protected readonly ?Context $context = null,
	)
	{

	}

	public function isCheckedAllAvailableEntityTypes(SegmentDataInterface $segmentData): bool
	{
		$availableEntityTypeIds = $this->getAvailableEntityTypeIds();

		return $segmentData->getEntityTypeId() === array_pop($availableEntityTypeIds);
	}

	abstract public function getAvailableEntityTypeIds(): array;

	public static function getTypeValue(): string
	{
		return static::getType()->value;
	}

	abstract public static function getType(): HandlerType;

	public function execute(): Result
	{
		$segmentCode = SegmentCode::tryFrom($this->segmentCode);

		$result = (new Result());
		if ($segmentCode === null)
		{
			return $result->addError(new Error('Unknown segmentCode: ' . $segmentCode));
		}

		$segmentData = Factory::getInstance()
			->getCollector($segmentCode)
			?->setLimit($this->limit)
			->setIsOnlyCalc($this->isOnlyCalc)
			->getSegmentData(
				$this->entityTypeId,
				$this->lastEntityId,
				$this->minimumDaysAfterLastClosedEntity,
			)
		;

		if ($segmentData === null)
		{
			return $result->addError(new Error('Unknown segment collector'));
		}

		if (!$segmentData->canProcessed())
		{
			return $result->addError(new Error('Segment data is not processed'));
		}

		return $this->processSegmentData($segmentData);
	}

	public function setEntityTypeId(int $entityTypeId = \CCrmOwnerType::Deal): self
	{
		if (!in_array($entityTypeId, $this->getAvailableEntityTypeIds(), true))
		{
			throw new ArgumentOutOfRangeException('entityTypeId');
		}

		$this->entityTypeId = $entityTypeId;

		return $this;
	}

	public function setLastEntityId(?int $lastEntityId = null): self
	{
		$this->lastEntityId = $lastEntityId;

		return $this;
	}

	public function setLastAssignmentId(?int $lastAssignmentId): self
	{
		$this->lastAssignmentId = $lastAssignmentId;

		return $this;
	}

	public function setLimit(int $limit): self
	{
		$this->limit = $limit;

		return $this;
	}

	public function setOffset(int $offset): self
	{
		$this->offset = $offset;

		return $this;
	}

	public function setIsOnlyCalc(bool $value): self
	{
		$this->isOnlyCalc = $value;

		return $this;
	}

	public function setMinimumDaysAfterLastClosedEntity(int $days): self
	{
		$this->minimumDaysAfterLastClosedEntity = $days;

		return $this;
	}

	protected function processSegmentData(SegmentDataInterface $segmentData): Result
	{
		$result = (new Result());

		$items = $segmentData->getItems();

		$result->setSegmentData($segmentData);
		if (empty($items))
		{
			return $result;
		}

		if ($this->isOnlyCalc)
		{
			return $result;
		}

		$lastAssignmentId = $this->lastAssignmentId;
		$strategyObtainingAssignmentId = null;

		$segmentItem = $this->context?->getSegmentItem();
		if ($segmentItem)
		{
			$strategyObtainingAssignmentId = AssignmentStrategies\Factory::getStrategy(
				AssignmentType::from($segmentItem->getAssignmentTypeId()),
				$segmentItem,
				$this->entityTypeId,
				$items,
			);
		}

		foreach ($items as $item)
		{
			$assignmentUserId = $strategyObtainingAssignmentId?->getAssignmentUserId($item, $lastAssignmentId);
			if ($assignmentUserId === null)
			{
				$fallbackUserId = (int)$item->getAssignedById();
				$assignmentUserId = $this->isActiveFallbackUser($items, $fallbackUserId) ? $fallbackUserId : 1;
			}

			$this->getOperation($item, $assignmentUserId)->launch();

			$lastAssignmentId = $assignmentUserId;
		}

		$segmentData->setLastAssignmentId($lastAssignmentId);

		return $result;
	}

	/**
	 * Fallback users of the whole portion are resolved by a single query on the first actual need.
	 */
	private function isActiveFallbackUser(array $items, int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$this->activeFallbackUserIds ??= $this->selectActiveUserIds($this->collectFallbackUserIds($items));

		return isset($this->activeFallbackUserIds[$userId]);
	}

	private function collectFallbackUserIds(array $items): array
	{
		$userIds = [];
		foreach ($items as $item)
		{
			$userId = (int)$item->getAssignedById();
			if ($userId > 0)
			{
				$userIds[$userId] = true;
			}
		}

		return array_keys($userIds);
	}

	private function selectActiveUserIds(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$activeUserIds = [];
		$rows = UserTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $userIds)
			->where('ACTIVE', 'Y')
			->exec()
		;
		while ($row = $rows->fetch())
		{
			$activeUserIds[(int)$row['ID']] = true;
		}

		return $activeUserIds;
	}

	abstract protected function getOperation(Item $item, int $lastAssignmentId): Operation;
}

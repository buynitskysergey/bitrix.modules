<?php

namespace Bitrix\Crm\RepeatSale\Schedule;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\RepeatSale\AvailabilityChecker;
use Bitrix\Crm\RepeatSale\Job\Entity\RepeatSaleJobTable;
use Bitrix\Crm\RepeatSale\Queue\Controller\RepeatSaleQueueController;
use Bitrix\Crm\RepeatSale\Queue\QueueItem;
use Bitrix\Crm\RepeatSale\Segment\Controller\RepeatSaleSegmentController;
use Bitrix\Crm\RepeatSale\Segment\Entity\RepeatSaleSegment;
use Bitrix\Crm\RepeatSale\Segment\SegmentCode;
use Bitrix\Crm\RepeatSale\Segment\SegmentItem;
use Bitrix\Crm\RepeatSale\Service\Handler\AiApproveHandler;
use Bitrix\Crm\RepeatSale\Service\Handler\AiScreeningHandler;
use Bitrix\Crm\RepeatSale\Service\Handler\ConfigurableHandler;
use Bitrix\Crm\RepeatSale\Service\Handler\HandlerType;
use Bitrix\Crm\RepeatSale\Service\Handler\RemainingHandler;
use Bitrix\Crm\RepeatSale\Service\Handler\SystemHandler;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Objectify\Collection;
use Bitrix\Main\ORM\Query\QueryHelper;
use Bitrix\Main\Type\Date;

final class Scheduler
{
	use Singleton;

	private bool $isOnlyCalc = false;

	public function execute(): void
	{
		$availabilityChecker = Container::getInstance()->getRepeatSaleAvailabilityChecker();
		if (!$availabilityChecker->isEnabled() || !$availabilityChecker->isItemsCountsLessThenLimit())
		{
			return;
		}

		$params = [
			'date' => (new Date())->getTimestamp(),
		];

		$queueController = RepeatSaleQueueController::getInstance();

		$jobs = $this->getSuitableJobs();
		foreach ($jobs as $job)
		{
			$segmentCode = $job->getSegment()->getCode();
			$itemParams = [
				'segmentCode' => $segmentCode,
				'segmentId' => $job->getSegmentId(),
			];

			$handlerTypeId = $this->getHandlerTypeId($segmentCode);

			if ($this->isOnlyCalc && $handlerTypeId !== SystemHandler::getTypeValue())
			{
				continue;
			}

			if (HandlerType::isAiHandler(HandlerType::fromValue($handlerTypeId)))
			{
				$isDisabledSegment = $this->tryDisableSegment($availabilityChecker, $job->getSegment(), $handlerTypeId);

				if ($isDisabledSegment)
				{
					continue;
				}
			}

			$queueItem = QueueItem::createFromArray([
				'jobId' => $job->getId(),
				'params' => array_merge($params, $itemParams),
				'isOnlyCalc' => $this->isOnlyCalc,
				'handlerTypeId' => $handlerTypeId,
			]);

			$isQueued = $queueController->add($queueItem)?->isSuccess() ?? false;

			$this->sendAnalytics();

			// safety net: the main path is the screening job finish handler, and the agent runs once
			// a day, so without this a day without any finished screening job leaves children unscheduled
			if ($isQueued)
			{
				$this->addChildrenJobsToQueueIfNotExists($job->getSegmentId());
			}
		}

		if ($this->isOnlyCalc)
		{
			Option::delete('crm', ['name' => 'repeat-sale-wait-only-calc-scheduler']);
		}
	}

	public function setOnlyCalc(bool $value = true): self
	{
		$this->isOnlyCalc = $value;

		return $this;
	}

	/**
	 * @return Collection
	 */
	private function getSuitableJobs(): Collection
	{
		$filter = [
			'=SEGMENT.BASE_SEGMENT_CODE' => null,
		];
		if (!$this->isOnlyCalc)
		{
			$filter['SEGMENT.IS_ENABLED'] = 'Y';
		}

		$query = RepeatSaleJobTable::query()
			->setSelect(['ID', 'SEGMENT_ID', 'SEGMENT.*'])
			->setFilter($filter)
		;

		// remaining segment must run after the holiday (ai_screening) one,
		// so any-purchase processing happens only after holiday screening.
		$caseExpression = new ExpressionField(
			'SORT_ORDER',
			"CASE
				WHEN %s = '" . SegmentCode::REMAINING->value . "' THEN 3
				WHEN %s = '" . SegmentCode::AI_SCREENING->value . "' THEN 2
				ELSE 1
			END",
			['SEGMENT.CODE', 'SEGMENT.CODE'],
		);

		$query
			->registerRuntimeField('SORT_ORDER', $caseExpression)
			->setOrder(['SORT_ORDER' => 'ASC'])
		;

		return QueryHelper::decompose($query);
	}

	private function sendAnalytics(): void
	{
		$event = new AnalyticsEvent('rs-add-queue-item', Dictionary::TOOL_CRM, Dictionary::CATEGORY_SYSTEM_INFORM);

		try
		{
			$event
				->setType(Dictionary::TYPE_AGENT)
				->send()
			;
		}
		catch (\Exception $e)
		{

		}
	}

	public function addChildrenJobsToQueueIfNotExists(int $parentSegmentId): void
	{
		// only-calc measures client coverage and creates no deals: an item queued in this mode would
		// also block the normal one for a day, since the queue dedupe by JOB_ID + HASH ignores the mode
		if ($this->isOnlyCalc)
		{
			return;
		}

		$segmentController = RepeatSaleSegmentController::getInstance();
		$parentSegment = $segmentController->getById($parentSegmentId);

		if (!$parentSegment)
		{
			return;
		}

		// a user segment has no code, and a null value in the filter below turns into IS NULL,
		// which matches every base segment instead of the children of this parent
		if ($parentSegment->getCode() === null)
		{
			return;
		}

		$systemSegments = $segmentController->getList([
			'select' => ['ID', 'CODE', 'JOB.ID'],
			'filter' => [
				'IS_SYSTEM' => 'Y',
				'IS_ENABLED' => 'Y',
				'BASE_SEGMENT_CODE' => $parentSegment->getCode(),
			],
		]);

		$params = [
			'date' => (new Date())->getTimestamp(),
		];

		$queueController = RepeatSaleQueueController::getInstance();

		foreach ($systemSegments as $systemSegment)
		{
			$job = $systemSegment->getJob();
			if ($job === null)
			{
				continue;
			}

			$segmentCode = $systemSegment->getCode();
			$itemParams = [
				'segmentCode' => $segmentCode,
				'segmentId' => $systemSegment->getId(),
			];

			$queueItem = QueueItem::createFromArray([
				'jobId' => $job->getId(),
				'params' => array_merge($params, $itemParams),
				'isOnlyCalc' => $this->isOnlyCalc,
				'handlerTypeId' => $this->getHandlerTypeId($segmentCode),
			]);

			$queueController->add($queueItem);
		}
	}

	private function getHandlerTypeId(?string $segmentCode): string
	{
		if ($segmentCode === SegmentCode::AI_SCREENING->value)
		{
			return AiScreeningHandler::getTypeValue();
		}

		if ($segmentCode === SegmentCode::AI_APPROVE->value)
		{
			return AiApproveHandler::getTypeValue();
		}

		if ($segmentCode === SegmentCode::REMAINING->value)
		{
			return RemainingHandler::getTypeValue();
		}

		if ($segmentCode === null)
		{
			return ConfigurableHandler::getTypeValue();
		}

		return SystemHandler::getTypeValue();
	}

	private function tryDisableSegment(
		AvailabilityChecker $availabilityChecker,
		RepeatSaleSegment $segmentEntity,
		string $handlerTypeId,
	): bool
	{
		if (
			Feature::enabled(Feature\RepeatSaleAiSegment::class)
			&& $availabilityChecker->isAiSegmentsAvailable()
			&& $handlerTypeId !== RemainingHandler::getTypeValue()
		)
		{
			return false;
		}

		if (
			Feature::enabled(Feature\RepeatSaleRemainingSegment::class)
			&& $availabilityChecker->isAiSegmentsAvailable()
			&& $handlerTypeId === RemainingHandler::getTypeValue()
		)
		{
			return false;
		}

		$segmentController = RepeatSaleSegmentController::getInstance();

		// reload with ASSIGNMENT_USERS: update() rebuilds responsible users from the DTO,
		// and the entity from getSuitableJobs() has no assignment relation loaded, so without
		// this reload the update would wipe the segment's managers.
		$segmentEntity = $segmentController->getById($segmentEntity->getId(), true);
		if ($segmentEntity === null)
		{
			return false;
		}

		$segment = SegmentItem::createFromEntity($segmentEntity);
		$segment
			->setIsEnabled(false)
			->setIsAutoDisabled(true)
		;

		$result = $segmentController->update($segment->getId(), $segment);

		// the child ai_approve is not scheduled directly (only base segments are),
		// so it must be disabled and marked in sync with its ai_screening parent
		// to keep statuses and the auto-disable "memory" consistent for restore.
		if ($segment->getCode() === SegmentCode::AI_SCREENING->value)
		{
			$childSegmentEntity = $segmentController->getByCode(SegmentCode::AI_APPROVE->value, true);
			if ($childSegmentEntity !== null)
			{
				$childSegment = SegmentItem::createFromEntity($childSegmentEntity);
				$childSegment
					->setIsEnabled(false)
					->setIsAutoDisabled(true)
				;

				$segmentController->update($childSegment->getId(), $childSegment);
			}
		}

		return $result->isSuccess();
	}
}

<?php

namespace Bitrix\Crm\RepeatSale\Segment;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Integration\Rest\Marketplace\Client;
use Bitrix\Crm\RepeatSale\Segment\Controller\RepeatSaleSegmentController;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class SegmentItemChecker
{
	use Singleton;

	/**
	 * Error code signalling that an AI segment cannot be enabled specifically because the
	 * marketplus subscription is unavailable (as opposed to missing rights or a disabled
	 * subsystem). The controller maps this reason to a purchase slider instead of a plain
	 * access-denied error.
	 */
	public const SUBSCRIPTION_UNAVAILABLE = 'CRM_REPEAT_SALE_SUBSCRIPTION_UNAVAILABLE';

	private ?SegmentItem $item = null;

	public function setItem(?SegmentItem $item): self
	{
		$this->item = $item;

		return $this;
	}

	public function setItemByActivity(array $activity): self
	{
		$segmentId = (int)($activity['PROVIDER_PARAMS']['SEGMENT_ID'] ?? 0);
		$entity = RepeatSaleSegmentController::getInstance()->getById($segmentId, true);
		if ($entity)
		{
			$this->item = SegmentItem::createFromEntity($entity);
		}

		return $this;
	}

	public function run(): Result
	{
		$result = new Result();

		$checker = Container::getInstance()->getRepeatSaleAvailabilityChecker();
		if (!$checker->isEnabled())
		{
			return $result->addError(new Error(
				Loc::getMessage('CRM_SEGMENT_ITEM_REPEAT_SALE_OFF'),
				'CRM_REPEAT_SALE_REPEAT_SALE_OFF',
			));
		}

		if (!$checker->hasPermission())
		{
			return $result->addError(new Error(
				Loc::getMessage('CRM_SEGMENT_ITEM_REPEAT_SALE_ACCESS_DENIED'),
				ErrorCode::ACCESS_DENIED,
			));
		}

		if (!$this->item)
		{
			return $result->addError(new Error(
				Loc::getMessage('CRM_SEGMENT_ITEM_NOT_FOUND'),
				ErrorCode::NOT_FOUND,
			));
		}

		if ($this->isTitleEmpty() || $this->isPromptEmpty() || $this->isMinimumDaysAfterLastClosedEntityInvalid())
		{
			return $result->addError(new Error(
				Loc::getMessage('CRM_SEGMENT_ITEM_INVALID'),
				ErrorCode::INVALID_ARG_VALUE,
			));
		}

		if (SegmentCode::isNeedAiModule($this->item->getCode()) && !$checker->isAiSegmentsAvailable())
		{
			if ($this->isSubscriptionUnavailable())
			{
				return $result->addError(new Error(
					Loc::getMessage('CRM_SEGMENT_ITEM_REPEAT_SALE_ACCESS_DENIED'),
					self::SUBSCRIPTION_UNAVAILABLE,
				));
			}

			return $result->addError(new Error(
				Loc::getMessage('CRM_SEGMENT_ITEM_REPEAT_SALE_ACCESS_DENIED'),
				ErrorCode::ACCESS_DENIED,
			));
		}

		return $result; // success
	}

	/**
	 * True when the AI segment is unavailable specifically because of the marketplus
	 * subscription (subsystem is enabled and the AI-segment feature is on, but the market
	 * subscription is overdue). Mirrors the subscription gate of
	 * AvailabilityChecker::isAiSegmentsAvailable() so the "subscription" reason can be told
	 * apart from a disabled subsystem or a turned-off feature.
	 */
	private function isSubscriptionUnavailable(): bool
	{
		if (!Feature::enabled(Feature\RepeatSaleAiSegment::class))
		{
			return false;
		}

		return (new Client())->isMarketOverdue();
	}

	private function isTitleEmpty(): bool
	{
		return empty(trim($this->item->getTitle()));
	}

	private function isPromptEmpty(): bool
	{
		return empty(trim($this->item->getPrompt()));
	}

	private function isMinimumDaysAfterLastClosedEntityInvalid(): bool
	{
		return $this->item->getMinimumDaysAfterLastClosedEntity() < 0;
	}
}

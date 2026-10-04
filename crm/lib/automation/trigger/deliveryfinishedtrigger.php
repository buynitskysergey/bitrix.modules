<?php

namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
Use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

/**
 * Class DeliveryFinishedTrigger
 * @package Bitrix\Crm\Automation\Trigger
 */
class DeliveryFinishedTrigger extends BaseTrigger
{
	public const EVENT_INITIATOR_KEY = 'initiatorUserId';
	protected const EVENT_INITIATOR_ID = 'Initiator';
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	use OrderReturnTrait;

	/**
	 * @inheritDoc
	 */
	public static function isSupported($entityTypeId)
	{
		return $entityTypeId === \CCrmOwnerType::Deal;
	}

	protected static function getOrderReturnFieldIds(): array
	{
		return [
			self::ORDER_RETURN_ORDER_ID,
		];
	}

	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventInitiatorProperty(),
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_DELIVERY_FINISHED_EVENT_DATE_TIME') ?? ''
				),
			],
			static::getOrderReturnProperties()
		);
	}

	public function getReturnValues(): ?array
	{
		return array_merge(parent::getReturnValues() ?? [], $this->buildOrderReturnValues(), [
			static::EVENT_INITIATOR_ID => $this->buildEventInitiatorValue(),
			static::EVENT_DATE_TIME_ID => static::buildEventDateTimeValue(),
		]);
	}

	/**
	 * @inheritDoc
	 */
	public static function getCode()
	{
		return 'DELIVERY_FINISHED';
	}

	/**
	 * @inheritDoc
	 */
	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_DELIVERY_FINISHED_NAME_1');
	}

	public static function getGroup(): array
	{
		return ['delivery'];
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_DELIVERY_FINISHED_DESCRIPTION') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_DELIVERY_FINISHED_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		$iconValue = 'o-package-receive';
		$icon = Outline::tryFrom($iconValue);

		if ($icon !== null)
		{
			return $icon->name;
		}

		return parent::getNodeIcon();
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::DELIVERY->value];
	}
}

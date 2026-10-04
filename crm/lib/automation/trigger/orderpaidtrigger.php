<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
Use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class OrderPaidTrigger extends BaseTrigger
{
	public const EVENT_INITIATOR_KEY = 'initiatorUserId';
	protected const EVENT_INITIATOR_ID = 'Initiator';
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	use OrderReturnTrait;

	public static function isSupported($entityTypeId)
	{
		return
			$entityTypeId === \CCrmOwnerType::Deal
			|| \CCrmOwnerType::isUseDynamicTypeBasedApproach($entityTypeId)
		;
	}

	protected static function getOrderReturnFieldIds(): array
	{
		return [
			self::ORDER_RETURN_SUM,
			self::ORDER_RETURN_CURRENCY,
			self::ORDER_RETURN_ORDER_ID,
		];
	}

	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventInitiatorProperty(),
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_ORDER_PAID_EVENT_DATE_TIME') ?? ''
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

	public static function getCode()
	{
		return 'ORDER_PAID';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_ORDER_PAID_NAME_1');
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_ORDER_PAID_DESCRIPTION') ?? '';
	}

	public static function getGroup(): array
	{
		return ['payment'];
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_ORDER_PAID_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::PACKAGE->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::PAYMENT->value];
	}
}

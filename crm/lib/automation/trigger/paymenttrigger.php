<?php

namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class PaymentTrigger extends BaseTrigger
{
	public const EVENT_INITIATOR_KEY = 'initiatorUserId';
	protected const EVENT_INITIATOR_ID = 'Initiator';
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	use OrderReturnTrait;

	public static function isSupported($entityTypeId)
	{
		return $entityTypeId === \CCrmOwnerType::Order;
	}

	protected static function getOrderReturnFieldIds(): array
	{
		return [
			self::ORDER_RETURN_SUM,
			self::ORDER_RETURN_CURRENCY,
			self::ORDER_RETURN_PAYMENT_ID,
		];
	}

	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventInitiatorProperty(),
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_PAYMENT_EVENT_DATE_TIME') ?? '',
				),
			],
			static::getOrderReturnProperties(),
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
		return 'PAYMENT';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_PAYMENT_NAME_1');
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_PAYMENT_DESCRIPTION') ?? '';
	}
}

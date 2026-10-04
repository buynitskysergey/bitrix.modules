<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Main\Loader;
Use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class FillTrackingNumberTrigger extends BaseTrigger
{
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	use OrderReturnTrait;

	public static function isSupported($entityTypeId)
	{
		return ($entityTypeId === \CCrmOwnerType::Order);
	}

	protected static function getOrderReturnFieldIds(): array
	{
		return [
			self::ORDER_RETURN_TRACKING_NUMBER,
			self::ORDER_RETURN_DELIVERY_SERVICE_ID,
			self::ORDER_RETURN_SHIPMENT_ID,
		];
	}

	/**
	 * A shipment keeps no acting user for the tracking number, so the node names no initiator.
	 */
	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_FILL_TRACKNUM_EVENT_DATE_TIME') ?? ''
				),
			],
			static::getOrderReturnProperties()
		);
	}

	public function getReturnValues(): ?array
	{
		return array_merge(parent::getReturnValues() ?? [], $this->buildOrderReturnValues(), [
			static::EVENT_DATE_TIME_ID => static::buildEventDateTimeValue(),
		]);
	}

	public static function isEnabled()
	{
		return Loader::includeModule('sale');
	}

	public static function getCode()
	{
		return 'FILL_TRACKNUM';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_FILL_TRACKNUM_NAME_1');
	}

	public function checkApplyRules(array $trigger)
	{
		if (!parent::checkApplyRules($trigger))
		{
			return false;
		}

		if (
			is_array($trigger['APPLY_RULES'])
			&& isset($trigger['APPLY_RULES']['DELIVERY_ID'])
			&& $trigger['APPLY_RULES']['DELIVERY_ID'] > 0
		)
		{
			$shipment = $this->getInputData('SHIPMENT');

			return (int)$trigger['APPLY_RULES']['DELIVERY_ID'] === (int)$shipment->getField('DELIVERY_ID');
		}
		return true;
	}

	protected static function getPropertiesMap(): array
	{
		return [
			[
				'Id' => 'DELIVERY_ID',
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_FILL_TRACKNUM_PROPERTY_SERVICE'),
				'Type' => 'select',
				'EmptyValueText' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_FILL_TRACKNUM_DEFAULT'),
				'Options' => array_values(array_map(
					function($item)
					{
						return ['value' => $item['ID'], 'name' => $item['NAME']];
					},
					\Bitrix\Sale\Delivery\Services\Manager::getActiveList()
				)),
			]
		];
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_FILL_TRACKNUM_DESCRIPTION') ?? '';
	}

	public static function getGroup(): array
	{
		return ['delivery'];
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_FILL_TRACKNUM_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::BLUE->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::DIGITS_123->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::DELIVERY->value];
	}
}

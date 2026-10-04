<?php

namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sale\DeliveryStatus;

/**
 * Sale RETURN fields (amount / currency / entity id / delivery status / tracking) shared by the
 * order, payment and delivery triggers. A single catalog describes every field once (type,
 * INPUT_DATA scalar key, name); each concrete trigger only lists the ids it exposes via
 * {@see getOrderReturnFieldIds()} and adds {@see getOrderReturnProperties()} to its own
 * getReturnProperties() next to the event fields it declares.
 *
 * Values are read from INPUT_DATA as scalars: the dispatchers (order / payment / shipment and the
 * invoice status handlers) extract sum / currency / id / status from the domain objects, because a
 * domain object does not survive payload serialization on a deferred workflow start (see
 * only scalar values in payload. Any parent RETURN is preserved through a
 * non-clobbering merge.
 */
trait OrderReturnTrait
{
	protected const ORDER_RETURN_SUM = 'OrderSum';
	protected const ORDER_RETURN_CURRENCY = 'OrderCurrency';
	protected const ORDER_RETURN_INVOICE_ID = 'InvoiceId';
	protected const ORDER_RETURN_ORDER_ID = 'OrderId';
	protected const ORDER_RETURN_PAYMENT_ID = 'PaymentId';
	protected const ORDER_RETURN_SHIPMENT_ID = 'ShipmentId';
	protected const ORDER_RETURN_STATUS_PREVIOUS = 'DeliveryStatusPrevious';
	protected const ORDER_RETURN_STATUS_ACTUAL = 'DeliveryStatusActual';
	protected const ORDER_RETURN_ALLOW_DELIVERY_PREVIOUS = 'AllowDeliveryPrevious';
	protected const ORDER_RETURN_ALLOW_DELIVERY_ACTUAL = 'AllowDeliveryActual';
	protected const ORDER_RETURN_TRACKING_NUMBER = 'TrackingNumber';
	protected const ORDER_RETURN_DELIVERY_SERVICE_ID = 'DeliveryServiceId';

	/** INPUT_DATA scalar keys filled by the dispatchers (public: referenced across the module). */
	public const ORDER_INPUT_SUM = 'EVENT_SUM';
	public const ORDER_INPUT_CURRENCY = 'EVENT_CURRENCY';
	public const ORDER_INPUT_INVOICE_ID = 'EVENT_INVOICE_ID';
	public const ORDER_INPUT_ORDER_ID = 'EVENT_ORDER_ID';
	public const ORDER_INPUT_PAYMENT_ID = 'EVENT_PAYMENT_ID';
	public const ORDER_INPUT_SHIPMENT_ID = 'EVENT_SHIPMENT_ID';
	public const ORDER_INPUT_STATUS_PREVIOUS = 'EVENT_STATUS_PREVIOUS';
	public const ORDER_INPUT_STATUS_ACTUAL = 'EVENT_STATUS_ACTUAL';
	public const ORDER_INPUT_TRACKING_NUMBER = 'EVENT_TRACKING_NUMBER';
	public const ORDER_INPUT_DELIVERY_SERVICE_ID = 'EVENT_DELIVERY_ID';

	/**
	 * The catalog fields alone, for a trigger that exposes nothing else. A trigger that also declares
	 * event fields lists both in its own getReturnProperties() / getReturnValues() instead.
	 */
	public static function getReturnProperties(): array
	{
		return static::getOrderReturnProperties();
	}

	public function getReturnValues(): ?array
	{
		return array_merge(parent::getReturnValues() ?? [], $this->buildOrderReturnValues());
	}

	/**
	 * Ids of the catalog fields this trigger exposes, in display order.
	 *
	 * @return string[]
	 */
	abstract protected static function getOrderReturnFieldIds(): array;

	/**
	 * @return array<string, array{type: string, inputKey: string, name: string, optionsProvider?: callable(): array}>
	 */
	private static function getOrderReturnCatalog(): array
	{
		return [
			self::ORDER_RETURN_SUM => [
				'type' => FieldType::DOUBLE,
				'inputKey' => self::ORDER_INPUT_SUM,
				'name' => 'CRM_AUTOMATION_TRIGGER_ORDER_RETURN_SUM',
			],
			self::ORDER_RETURN_CURRENCY => [
				'type' => FieldType::STRING,
				'inputKey' => self::ORDER_INPUT_CURRENCY,
				'name' => 'CRM_AUTOMATION_TRIGGER_ORDER_RETURN_CURRENCY',
			],
			self::ORDER_RETURN_INVOICE_ID => [
				'type' => FieldType::INT,
				'inputKey' => self::ORDER_INPUT_INVOICE_ID,
				'name' => 'CRM_AUTOMATION_TRIGGER_ORDER_RETURN_INVOICE_ID',
			],
			self::ORDER_RETURN_ORDER_ID => [
				'type' => FieldType::INT,
				'inputKey' => self::ORDER_INPUT_ORDER_ID,
				'name' => 'CRM_AUTOMATION_TRIGGER_ORDER_RETURN_ORDER_ID',
			],
			self::ORDER_RETURN_PAYMENT_ID => [
				'type' => FieldType::INT,
				'inputKey' => self::ORDER_INPUT_PAYMENT_ID,
				'name' => 'CRM_AUTOMATION_TRIGGER_ORDER_RETURN_PAYMENT_ID',
			],
			self::ORDER_RETURN_SHIPMENT_ID => [
				'type' => FieldType::INT,
				'inputKey' => self::ORDER_INPUT_SHIPMENT_ID,
				'name' => 'CRM_AUTOMATION_TRIGGER_ORDER_RETURN_SHIPMENT_ID',
			],
			self::ORDER_RETURN_STATUS_PREVIOUS => [
				'type' => FieldType::SELECT,
				'inputKey' => self::ORDER_INPUT_STATUS_PREVIOUS,
				'name' => 'CRM_AUTOMATION_TRIGGER_ORDER_RETURN_STATUS_PREVIOUS',
				'optionsProvider' => static fn(): array => self::getDeliveryStatusOptions(),
			],
			self::ORDER_RETURN_STATUS_ACTUAL => [
				'type' => FieldType::SELECT,
				'inputKey' => self::ORDER_INPUT_STATUS_ACTUAL,
				'name' => 'CRM_AUTOMATION_TRIGGER_ORDER_RETURN_STATUS_ACTUAL',
				'optionsProvider' => static fn(): array => self::getDeliveryStatusOptions(),
			],
			self::ORDER_RETURN_ALLOW_DELIVERY_PREVIOUS => [
				'type' => FieldType::SELECT,
				'inputKey' => self::ORDER_INPUT_STATUS_PREVIOUS,
				'name' => 'CRM_AUTOMATION_TRIGGER_ORDER_RETURN_ALLOW_DELIVERY_PREVIOUS',
				'optionsProvider' => static fn(): array => self::getAllowDeliveryOptions(),
			],
			self::ORDER_RETURN_ALLOW_DELIVERY_ACTUAL => [
				'type' => FieldType::SELECT,
				'inputKey' => self::ORDER_INPUT_STATUS_ACTUAL,
				'name' => 'CRM_AUTOMATION_TRIGGER_ORDER_RETURN_ALLOW_DELIVERY_ACTUAL',
				'optionsProvider' => static fn(): array => self::getAllowDeliveryOptions(),
			],
			self::ORDER_RETURN_TRACKING_NUMBER => [
				'type' => FieldType::STRING,
				'inputKey' => self::ORDER_INPUT_TRACKING_NUMBER,
				'name' => 'CRM_AUTOMATION_TRIGGER_ORDER_RETURN_TRACKING_NUMBER',
			],
			self::ORDER_RETURN_DELIVERY_SERVICE_ID => [
				'type' => FieldType::INT,
				'inputKey' => self::ORDER_INPUT_DELIVERY_SERVICE_ID,
				'name' => 'CRM_AUTOMATION_TRIGGER_ORDER_RETURN_DELIVERY_SERVICE_ID',
			],
		];
	}

	protected static function getOrderReturnProperties(): array
	{
		Loc::loadMessages(__FILE__);

		$catalog = static::getOrderReturnCatalog();
		$properties = [];
		foreach (static::getOrderReturnFieldIds() as $id)
		{
			$field = $catalog[$id];
			$property = [
				'Id' => $id,
				'Name' => Loc::getMessage($field['name']) ?? '',
				'Type' => $field['type'],
				'Default' => static::getOrderReturnEmptyValue($field['type']),
			];

			if ($field['type'] === FieldType::SELECT && isset($field['optionsProvider']))
			{
				$property['Options'] = ($field['optionsProvider'])();
			}

			$properties[] = $property;
		}

		return $properties;
	}

	/**
	 * Delivery status code => localized name map for the current language, built from the
	 * portal-configured statuses. Empty when the sale module is unavailable.
	 *
	 * @return array<string, string>
	 */
	private static function getDeliveryStatusOptions(): array
	{
		if (!Loader::includeModule('sale'))
		{
			return [];
		}

		return DeliveryStatus::getAllStatusesNames();
	}

	/**
	 * ALLOW_DELIVERY flag value => localized name map.
	 *
	 * @return array<string, string>
	 */
	private static function getAllowDeliveryOptions(): array
	{
		return [
			'Y' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_ORDER_RETURN_ALLOW_DELIVERY_YES') ?? '',
			'N' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_ORDER_RETURN_ALLOW_DELIVERY_NO') ?? '',
		];
	}

	protected function buildOrderReturnValues(): array
	{
		$catalog = static::getOrderReturnCatalog();
		$values = [];
		foreach (static::getOrderReturnFieldIds() as $id)
		{
			$field = $catalog[$id];
			$values[$id] = static::castOrderReturnValue($this->getInputData($field['inputKey']), $field['type']);
		}

		return $values;
	}

	protected static function castOrderReturnValue($value, string $type)
	{
		if ($value === null)
		{
			return static::getOrderReturnEmptyValue($type);
		}

		return match ($type)
		{
			FieldType::INT => (int)$value,
			FieldType::DOUBLE => (float)$value,
			default => (string)$value,
		};
	}

	protected static function getOrderReturnEmptyValue(string $type)
	{
		return match ($type)
		{
			FieldType::INT => 0,
			FieldType::DOUBLE => 0.0,
			default => '',
		};
	}
}

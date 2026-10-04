<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\RelationIdentifier;
use Bitrix\Crm\Service\Container;
Use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class InvoiceTrigger extends BaseTrigger
{
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	use OrderReturnTrait;

	public static function isSupported($entityTypeId)
	{
		return ($entityTypeId === \CCrmOwnerType::Deal);
	}

	protected static function getOrderReturnFieldIds(): array
	{
		return [
			self::ORDER_RETURN_SUM,
			self::ORDER_RETURN_CURRENCY,
			self::ORDER_RETURN_INVOICE_ID,
		];
	}

	/**
	 * The payment of an invoice is registered by the system as often as by a person, and the status
	 * handlers name no acting user, so the node names no initiator.
	 */
	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_INVOICE_EVENT_DATE_TIME') ?? ''
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

	public static function getCode()
	{
		return 'INVOICE';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_INVOICE_NAME_1');
	}

	/**
	 * @deprecated
	 * @param $params
	 */
	public static function onAfterCrmInvoiceSetStatus($params)
	{
	}

	public static function onInvoiceStatusChanged($id, $statusId)
	{
		if (\CCrmStatusInvoice::isStatusSuccess($statusId))
		{
			$iterator = \CCrmInvoice::GetList(
				array(),
				array('ID' => $id, 'CHECK_PERMISSIONS' => 'N'),
				false,
				false,
				array('ID', 'UF_DEAL_ID', 'PRICE', 'CURRENCY')
			);
			$fields = is_object($iterator) ? $iterator->fetch() : null;
			$dealId = 0;
			if(is_array($fields))
			{
				$dealId = isset($fields['UF_DEAL_ID']) ? $fields['UF_DEAL_ID'] : 0;
			}

			if ($dealId > 0)
			{
				static::execute(array(array(
					'OWNER_TYPE_ID' => \CCrmOwnerType::Deal,
					'OWNER_ID' => $dealId
				)), array(
					'INVOICE_ID' => $id,
					self::ORDER_INPUT_INVOICE_ID => (int)$id,
					self::ORDER_INPUT_SUM => (float)($fields['PRICE'] ?? 0),
					self::ORDER_INPUT_CURRENCY => (string)($fields['CURRENCY'] ?? ''),
				));
			}
		}
	}

	public static function onSmartInvoiceStatusChanged(Item\SmartInvoice $item): void
	{
		$factory = Container::getInstance()->getFactory($item->getEntityTypeId());
		if (!$factory)
		{
			return;
		}

		$stage = $factory->getStage($item->getStageId());
		if (!$stage || $stage->getSemantics() !== PhaseSemantics::SUCCESS)
		{
			return;
		}

		$dealsRelation = Container::getInstance()->getRelationManager()->getRelation(
			new RelationIdentifier(
				\CCrmOwnerType::Deal,
				$item->getEntityTypeId()
			)
		);
		if (!$dealsRelation)
		{
			return;
		}

		$dealIdentifiers = $dealsRelation->getParentElements(ItemIdentifier::createByItem($item));
		foreach ($dealIdentifiers as $identifier)
		{
			static::execute(
				[
					[
						'OWNER_TYPE_ID' => $identifier->getEntityTypeId(),
						'OWNER_ID' => $identifier->getEntityId()
					]
				],
				[
					'SMART_INVOICE_ID' => $item->getId(),
					self::ORDER_INPUT_INVOICE_ID => (int)$item->getId(),
					self::ORDER_INPUT_SUM => (float)($item->getOpportunity() ?? 0),
					self::ORDER_INPUT_CURRENCY => (string)($item->getCurrencyId() ?? ''),
				]
			);
		}
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_INVOICE_DESCRIPTION') ?? '';
	}

	public static function getGroup(): array
	{
		return ['payment'];
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_INVOICE_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::INVOICE->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::PAYMENT->value];
	}
}

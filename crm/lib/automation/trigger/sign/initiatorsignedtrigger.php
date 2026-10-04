<?php
namespace Bitrix\Crm\Automation\Trigger\Sign;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main;
use Bitrix\Sign;
use Bitrix\Crm;
use Bitrix\Crm\Automation;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class InitiatorSignedTrigger extends Automation\Trigger\BaseTrigger
{
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	use SignTriggerReturnDataTrait;

	public static function isEnabled()
	{
		return Main\Loader::includeModule('sign')
			&& Sign\Config\Storage::instance()->isAvailable()
		;
	}

	public static function isSupported($entityTypeId)
	{
		return ($entityTypeId === \CCrmOwnerType::Deal || $entityTypeId === \CCrmOwnerType::SmartDocument);
	}

	public static function executeBySmartDocumentId(
		int $smartDocumentId,
		array $inputData = null
	): Main\Result
	{
		$bindings = [
			[
				'OWNER_ID' => $smartDocumentId,
				'OWNER_TYPE_ID' => \CCrmOwnerType::SmartDocument,
			]
		];
		$itemId = new Crm\ItemIdentifier(
			\CCrmOwnerType::SmartDocument,
			$smartDocumentId
		);
		$itemId = (new Crm\Relation\RelationManager)->getParentElements($itemId)[0] ?? null;
		if (
			$itemId
			&& $itemId->getEntityId()
			&& $itemId->getEntityTypeId() === \CCrmOwnerType::Deal
		)
		{
			$bindings[] = [
				'OWNER_ID' => $itemId->getEntityId(),
				'OWNER_TYPE_ID' => $itemId->getEntityTypeId(),
			];
		}

		return static::execute($bindings, $inputData);
	}

	public static function getCode()
	{
		return 'SIGN_INITIATOR_SIGNING';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_SIGN_INITIATOR_SIGNED_NAME_2');
	}

	public static function getGroup(): array
	{
		return ['paperwork'];
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_SIGN_INITIATOR_SIGNED_DESCRIPTION') ?? '';
	}

	public function setInputData($data)
	{
		if (is_callable([$this, 'setReturnValues']))
		{
			$this->setReturnValues(static::buildSignReturnValues(is_array($data) ? $data : []));
		}

		return parent::setInputData($data);
	}

	/**
	 * The B2B events reach crm through the timeline callback of the signing service: an acting user
	 * could only arrive with that callback, and nothing inside the portal fills one, so the node names
	 * no initiator. The member who signed is reported by the signer fields instead.
	 */
	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_SIGN_INITIATOR_SIGNED_EVENT_DATE_TIME') ?? ''
				),
			],
			static::getSignReturnProperties()
		);
	}

	public function getReturnValues(): ?array
	{
		return array_merge(parent::getReturnValues() ?? [], [
			static::EVENT_DATE_TIME_ID => static::buildEventDateTimeValue(),
		]);
	}

	/**
	 * ON_SIGN names the member who signed, so the member fields are reported as well. The B2B nodes are
	 * each raised by a single event, so the event type is not offered as a field.
	 */
	protected static function getSignReturnFieldIds(): array
	{
		return [
			self::RETURN_SIGN_DOCUMENT_ID,
			self::RETURN_SIGN_MEMBER_ROLE,
			self::RETURN_SIGN_INITIATED_BY_TYPE,
			self::RETURN_SIGNER_USER,
			self::RETURN_SIGNER_NAME,
		];
	}

	public static function toArray()
	{
		$result = parent::toArray();
		if (
			static::isEnabled()
			&& Main\Loader::includeModule('bitrix24')
			&& !\Bitrix\Bitrix24\Feature::isFeatureEnabled('sign_automation')
		)
		{
			$result['LOCKED'] = [
				'INFO_CODE' => 'limit_crm_sign_automation',
			];
		}

		return $result;
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_SIGN_INITIATOR_SIGNED_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::ORANGE->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::SIGN->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::DOCUMENT_FLOW->value];
	}
}

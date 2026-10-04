<?php

namespace Bitrix\Crm\Automation\Trigger\Sign\B2e;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Integration\CRM\Model\EventData;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

final class SigningTrigger extends AbstractB2eDocumentTrigger
{
	protected const EVENT_INITIATOR_ID = 'Initiator';
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	public static function getCode(): string
	{
		return 'B2E_SIGNING';
	}

	/**
	 * Every event of this node names an acting user: the member who signed, or the author on the start
	 * of the signing, where there is no member at all. The initiator is reported only while the
	 * installed sign sends it.
	 */
	public static function getReturnProperties(): array
	{
		$eventProperties = [];
		if (static::isInitiatorSentBySign())
		{
			$eventProperties[] = static::getEventInitiatorProperty();
		}
		$eventProperties[] = static::getEventDateTimeProperty(
			Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_SIGNING_EVENT_DATE_TIME') ?? ''
		);

		return array_merge($eventProperties, static::getSignReturnProperties());
	}

	public function getReturnValues(): ?array
	{
		$eventValues = [static::EVENT_DATE_TIME_ID => static::buildEventDateTimeValue()];
		if (static::isInitiatorSentBySign())
		{
			$eventValues[static::EVENT_INITIATOR_ID] = $this->buildEventInitiatorValue();
		}

		return array_merge(parent::getReturnValues() ?? [], $eventValues);
	}

	protected static function getSignReturnFieldIds(): array
	{
		return [
			self::RETURN_SIGN_DOCUMENT_ID,
			self::RETURN_SIGN_MEMBER_ROLE,
			self::RETURN_SIGN_INITIATED_BY_TYPE,
			self::RETURN_SIGN_EVENT_TYPE,
			self::RETURN_SIGNER_USER,
			self::RETURN_SIGNER_NAME,
		];
	}

	protected static function getSignEventTypes(): array
	{
		return [
			EventData::TYPE_ON_SIGNED_BY_EDITOR,
			EventData::TYPE_ON_SIGNED_BY_REVIEWER,
			EventData::TYPE_ON_SIGNED_BY_EMPLOYEE,
			EventData::TYPE_ON_STARTED,
		];
	}

	public static function getName(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_SIGNING_NAME') ?? '';
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_SIGNING_DESCRIPTION') ?? '';
	}

	public static function getNodeName(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_SIGNING_NODE_NAME') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_SIGNING_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::ORANGE->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::FILE_WITH_CHECK_2->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::SIGN->value];
	}
}

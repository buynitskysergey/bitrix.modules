<?php

namespace Bitrix\Crm\Automation\Trigger\Sign\B2e;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

final class CoordinationTrigger extends AbstractB2eDocumentTrigger
{
	protected const EVENT_INITIATOR_ID = 'Initiator';
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	public static function getCode(): string
	{
		return 'B2E_COORDINATION';
	}

	public static function getName(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COORDINATION_NAME') ?? '';
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COORDINATION_DESCRIPTION') ?? '';
	}

	public static function getNodeName(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COORDINATION_NODE_NAME') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COORDINATION_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::ORANGE->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::LIKE->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::SIGN->value];
	}

	/**
	 * The coordination event names the reviewer who acted, so the node reports the initiator, but only
	 * while the installed sign sends it.
	 */
	public static function getReturnProperties(): array
	{
		$eventProperties = [];
		if (static::isInitiatorSentBySign())
		{
			$eventProperties[] = static::getEventInitiatorProperty();
		}
		$eventProperties[] = static::getEventDateTimeProperty(
			Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COORDINATION_EVENT_DATE_TIME') ?? ''
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

	/**
	 * The coordination event is emitted for the document; the reviewer behind it is resolved as the
	 * first one by id rather than the one who acted, so no member fields are reported.
	 */
	protected static function getSignReturnFieldIds(): array
	{
		return [
			self::RETURN_SIGN_DOCUMENT_ID,
			self::RETURN_SIGN_INITIATED_BY_TYPE,
		];
	}
}

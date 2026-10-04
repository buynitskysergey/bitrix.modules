<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
Use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class GuestReturnTrigger extends BaseTrigger
{
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	public static function getCode()
	{
		return 'GUEST_RETURN';
	}

	/**
	 * The site is revisited by the client, so the node names no portal initiator.
	 */
	public static function getReturnProperties(): array
	{
		return [
			static::getEventDateTimeProperty(
				Loc::getMessage('CRM_AUTOMATION_TRIGGER_GUEST_RETURN_EVENT_DATE_TIME') ?? ''
			),
		];
	}

	public function getReturnValues(): ?array
	{
		return array_merge(parent::getReturnValues() ?? [], [
			static::EVENT_DATE_TIME_ID => static::buildEventDateTimeValue(),
		]);
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_GUEST_RETURN_NAME_1');
	}

	public static function getGroup(): array
	{
		return ['other'];
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_GUEST_RETURN_DESCRIPTION') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_GUEST_RETURN_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::REGISTRATION_ON_SITE->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::MARKETING->value];
	}
}

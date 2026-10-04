<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
Use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class EmailTrigger extends BaseTrigger
{
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	use EmailReturnTrait;

	public static function getCode()
	{
		return 'EMAIL';
	}

	/**
	 * An incoming letter is written by the client, so the node names no portal initiator.
	 */
	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_EMAIL_EVENT_DATE_TIME') ?? ''
				),
			],
			static::getEmailReturnProperties()
		);
	}

	public function getReturnValues(): ?array
	{
		return array_merge(parent::getReturnValues() ?? [], [
			static::EVENT_DATE_TIME_ID => static::buildEventDateTimeValue(),
		]);
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_EMAIL_NAME_1');
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_EMAIL_DESCRIPTION') ?? '';
	}

	public static function getGroup(): array
	{
		return ['clientCommunication'];
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_EMAIL_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::MAIL_RETURN->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::CLIENT_COMMUNICATION->value];
	}
}

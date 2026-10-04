<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;

Loc::loadMessages(__FILE__);

class MissedCallTrigger extends CallTrigger
{
	public static function isEnabled()
	{
		return static::hasLines();
	}

	public static function getCode()
	{
		return 'MISSED_CALL';
	}

	/**
	 * Same fields as an incoming call, but the date/time is the moment the call was missed.
	 */
	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_MISSED_CALL_EVENT_DATE_TIME') ?? ''
				),
			],
			static::getCallReturnProperties()
		);
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_MISSED_CALL_NAME_1');
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_MISSED_CALL_DESCRIPTION') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_MISSED_CALL_NODE_DESCRIPTION') ?? '';
	}

	public static function getGroup(): array
	{
		return ['clientCommunication'];
	}
}
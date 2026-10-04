<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Localization\Loc;
use Bitrix\Crm\Integration;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class OpenLineMessageTrigger extends OpenLineTrigger
{
	protected const RETURN_OL_MESSAGE_TEXT = 'MessageText';

	public static function getCode()
	{
		return 'OPENLINE_MSG';
	}

	/**
	 * Same chat fields as the first-message trigger, but the date/time is the moment this very message
	 * arrived.
	 */
	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_MESSAGE_EVENT_DATE_TIME') ?? ''
				),
			],
			static::getOpenLineReturnProperties()
		);
	}

	protected static function getOpenLineExtraReturnProperties(): array
	{
		return [
			[
				'Id' => self::RETURN_OL_MESSAGE_TEXT,
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_MESSAGE_RETURN_TEXT'),
				'Type' => FieldType::TEXT,
				'Default' => null,
			],
		];
	}

	protected function buildOpenLineExtraReturnValues(): array
	{
		$message = $this->getInputData('MESSAGE');
		$text = is_array($message) ? ($message['PLAIN_TEXT'] ?? $message['TEXT'] ?? '') : '';

		return [
			self::RETURN_OL_MESSAGE_TEXT => (string)$text,
		];
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_MESSAGE_NAME_1');
	}

	public static function getGroup(): array
	{
		return ['clientCommunication'];
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_MESSAGE_DESCRIPTION') ?? '';
	}

	protected static function getPropertiesMap(): array
	{
		$map = parent::getPropertiesMap();
		$map[] = [
			'Id' => 'msg_text',
			'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_MESSAGE_PROPERTY_MSG_TEXT'),
			'Type' => 'string',
		];

		return $map;
	}

	public static function getNodeName(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_MESSAGE_NODE_NAME') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_MESSAGE_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::CLIENT_CHAT->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::CLIENT_COMMUNICATION->value];
	}
}

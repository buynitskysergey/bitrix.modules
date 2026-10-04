<?php

namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class EmailReadTrigger extends BaseTrigger
{
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	use EmailReturnTrait;

	public static function getCode()
	{
		return 'EMAIL_READ';
	}

	/**
	 * The letter is opened by the client, so the node names no portal initiator.
	 */
	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_EMAIL_READ_EVENT_DATE_TIME') ?? ''
				),
			],
			static::getEmailReturnProperties()
		);
	}

	/**
	 * The read moment is stamped into SETTINGS.READ_CONFIRMED by the dispatcher, so the node reports when
	 * the letter was opened and not when the workflow reached the node.
	 */
	public function getReturnValues(): ?array
	{
		$settings = (array)($this->getInputData('SETTINGS') ?? []);
		$readConfirmed = (int)($settings['READ_CONFIRMED'] ?? 0);
		$eventDateTime = $readConfirmed > 0
			? DateTime::createFromTimestamp($readConfirmed)->format(DateTime::getFormat())
			: static::buildEventDateTimeValue()
		;

		return array_merge(parent::getReturnValues() ?? [], [
			static::EVENT_DATE_TIME_ID => $eventDateTime,
		]);
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_EMAIL_READ_NAME_1');
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_EMAIL_READ_DESCRIPTION') ?? '';
	}

	public static function getGroup(): array
	{
		return ['clientCommunication'];
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_EMAIL_READ_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::MAIL_OPEN->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::CLIENT_COMMUNICATION->value];
	}
}

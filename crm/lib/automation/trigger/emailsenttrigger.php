<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
Use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class EmailSentTrigger extends BaseTrigger
{
	protected const EVENT_INITIATOR_ID = 'Initiator';
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	use EmailReturnTrait;

	public static function getCode()
	{
		return 'EMAIL_SENT';
	}

	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventInitiatorProperty(),
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_EMAIL_SENT_EVENT_DATE_TIME') ?? ''
				),
			],
			static::getEmailReturnProperties()
		);
	}

	public function getReturnValues(): ?array
	{
		return array_merge(parent::getReturnValues() ?? [], [
			static::EVENT_INITIATOR_ID => $this->buildEventInitiatorValue(),
			static::EVENT_DATE_TIME_ID => static::buildEventDateTimeValue(),
		]);
	}

	/**
	 * Outgoing email is authored by an employee: the initiator is the activity author.
	 */
	protected function resolveEventInitiatorUserId(): ?int
	{
		$authorId = (int)($this->getInputData('AUTHOR_ID') ?? 0);

		return $authorId > 0 ? $authorId : null;
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_EMAIL_SENT_NAME_1');
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_EMAIL_SENT_DESCRIPTION') ?? '';
	}

	public static function getGroup(): array
	{
		return ['clientCommunication'];
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_EMAIL_SENT_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::MAIL_SEND->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::CLIENT_COMMUNICATION->value];
	}
}

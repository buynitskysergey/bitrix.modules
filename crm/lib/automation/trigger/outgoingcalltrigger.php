<?php

namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

class OutgoingCallTrigger extends BaseTrigger
{
	protected const EVENT_INITIATOR_ID = 'Initiator';
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	use CallReturnTrait;

	public static function getCode()
	{
		return 'OUTGOING_CALL';
	}

	/**
	 * No status field: the trigger is raised from the provider-agnostic
	 * {@see \Bitrix\Crm\Activity\Provider\Call::onAfterAdd()}, which is not given the call object,
	 * so an outgoing call has no lifecycle status to report.
	 */
	protected static function getCallReturnFieldIds(): array
	{
		return [self::RETURN_CALL_PHONE, self::RETURN_CALL_DIRECTION];
	}

	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventInitiatorProperty(),
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_OUTGOING_CALL_EVENT_DATE_TIME') ?? '',
				),
			],
			static::getCallReturnProperties(),
		);
	}

	public function getReturnValues(): ?array
	{
		return array_merge(parent::getReturnValues() ?? [], [
			static::EVENT_INITIATOR_ID => $this->buildEventInitiatorValue(),
			static::EVENT_DATE_TIME_ID => static::buildEventDateTimeValue(),
		]);
	}

	public function setInputData($data)
	{
		parent::setInputData($data);

		if (is_callable([$this, 'setReturnValues']))
		{
			$communications = $this->getInputData('COMMUNICATIONS');
			$phone = is_array($communications) ? ($communications[0]['VALUE'] ?? '') : '';

			$this->setReturnValues($this->buildCallReturnValues($phone, 'outgoing'));
		}

		return $this;
	}

	/**
	 * Outgoing call is initiated by the activity author.
	 */
	protected function resolveEventInitiatorUserId(): ?int
	{
		$authorId = (int)($this->getInputData('AUTHOR_ID') ?? 0);

		return $authorId > 0 ? $authorId : null;
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OUTGOING_CALL_NAME_1');
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OUTGOING_CALL_DESCRIPTION') ?? '';
	}

	public static function getGroup(): array
	{
		return ['clientCommunication'];
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OUTGOING_CALL_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::PHONE_OUT->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::CLIENT_COMMUNICATION->value];
	}
}

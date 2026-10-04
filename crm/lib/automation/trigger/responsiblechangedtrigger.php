<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Bizproc\FieldType;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class ResponsibleChangedTrigger extends BaseTrigger
{
	protected const EVENT_INITIATOR_ID = 'Initiator';
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	private const RETURN_PREVIOUS_RESPONSIBLE_ID = 'PREVIOUS_RESPONSIBLE_ID';
	private const RETURN_RESPONSIBLE_ID = 'RESPONSIBLE_ID';

	// Dedicated scalar INPUT_DATA keys the dispatcher fills with the old/new responsible user id (payload rule:
	// scalars only). Keeps the trigger target-independent: it never reads the diff-contract ACTUAL/PREVIOUS_FIELDS.
	private const PREVIOUS_RESPONSIBLE_KEY = 'previousResponsibleId';
	private const RESPONSIBLE_KEY = 'responsibleId';

	public static function getCode()
	{
		return 'RESP_CHANGED';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_RESPONSIBLE_CHANGED_NAME_1');
	}

	public static function getGroup(): array
	{
		return ['elementControl'];
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_RESPONSIBLE_CHANGED_DESCRIPTION') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_RESPONSIBLE_CHANGED_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::BLUE->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::PERSON->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::SALES_CRM->value];
	}

	public function getReturnValues(): ?array
	{
		return array_merge(parent::getReturnValues() ?? [], [
			static::EVENT_INITIATOR_ID => $this->buildEventInitiatorValue(),
			static::EVENT_DATE_TIME_ID => static::buildEventDateTimeValue(),
			self::RETURN_PREVIOUS_RESPONSIBLE_ID => static::formatUserValue($this->getInputData(self::PREVIOUS_RESPONSIBLE_KEY)),
			self::RETURN_RESPONSIBLE_ID => static::formatUserValue($this->getInputData(self::RESPONSIBLE_KEY)),
		]);
	}

	public static function getReturnProperties(): array
	{
		return [
			static::getEventInitiatorProperty(),
			static::getEventDateTimeProperty(
				Loc::getMessage('CRM_AUTOMATION_TRIGGER_RESPONSIBLE_CHANGED_EVENT_DATE_TIME') ?? ''
			),
			[
				'Id' => self::RETURN_PREVIOUS_RESPONSIBLE_ID,
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_RESPONSIBLE_CHANGED_RETURN_PREVIOUS'),
				'Type' => FieldType::USER,
				'Default' => null,
			],
			[
				'Id' => self::RETURN_RESPONSIBLE_ID,
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_RESPONSIBLE_CHANGED_RETURN_CURRENT'),
				'Type' => FieldType::USER,
				'Default' => null,
			],
		];
	}

	private static function formatUserValue(mixed $userId): ?string
	{
		$userId = (int)$userId;

		return $userId > 0 ? 'user_' . $userId : null;
	}
}

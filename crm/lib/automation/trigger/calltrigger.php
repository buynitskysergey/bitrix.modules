<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class CallTrigger extends BaseTrigger
{
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	use CallReturnTrait;

	protected static function hasLines()
	{
		return (Loader::includeModule('voximplant') && \CVoxImplantHttp::VERSION >= 19);
	}

	/**
	 * Incoming calls are raised by voximplant off a live VI\Call, so the lifecycle status is available.
	 */
	protected static function getCallReturnFieldIds(): array
	{
		return [self::RETURN_CALL_PHONE, self::RETURN_CALL_DIRECTION, self::RETURN_CALL_STATUS];
	}

	/**
	 * An incoming call is placed by the client, so the node names no portal initiator.
	 */
	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_CALL_EVENT_DATE_TIME') ?? ''
				),
			],
			static::getCallReturnProperties()
		);
	}

	public function getReturnValues(): ?array
	{
		return array_merge(parent::getReturnValues() ?? [], [
			static::EVENT_DATE_TIME_ID => static::buildEventDateTimeValue(),
		]);
	}

	public function setInputData($data)
	{
		parent::setInputData($data);

		if (is_callable([$this, 'setReturnValues']))
		{
			$this->setReturnValues($this->buildCallReturnValues(
				$this->getInputData('CALL_PHONE_NUMBER'),
				$this->getInputData('CALL_DIRECTION'),
				$this->getInputData('CALL_STATUS'),
			));
		}

		return $this;
	}

	public static function getCode()
	{
		return 'CALL';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_CALL_NAME_1');
	}

	public function checkApplyRules(array $trigger)
	{
		if (!parent::checkApplyRules($trigger))
		{
			return false;
		}

		if (empty($trigger['APPLY_RULES']['LINE_NUMBER']))
		{
			return true;
		}

		$lineA = (string) $trigger['APPLY_RULES']['LINE_NUMBER'];
		$lineB = (string) $this->getInputData('LINE_NUMBER');

		return ($lineA === $lineB);
	}

	protected static function getPropertiesMap(): array
	{
		if (!static::hasLines())
		{
			return [];
		}

		return [
			[
				'Id' => 'LINE_NUMBER',
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_CALL_PROPERTY_LINE'),
				'Type' => 'select',
				'EmptyValueText' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_CALL_DEFAULT_LINE'),
				'Settings' => [
					'OptionsLoader' => [
						'type' => 'component',
						'component' => 'bitrix:crm.automation',
						'action' => 'getCallLines',
						'mode' => 'class',
					],
				],
			]
		];
	}

	public static function getGroup(): array
	{
		return ['clientCommunication'];
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_CALL_DESCRIPTION') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_CALL_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::PHONE_IN->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::CLIENT_COMMUNICATION->value];
	}
}

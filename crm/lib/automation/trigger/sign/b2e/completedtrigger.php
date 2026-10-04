<?php

namespace Bitrix\Crm\Automation\Trigger\Sign\B2e;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Integration\CRM\Model\EventData;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

final class CompletedTrigger extends AbstractB2eDocumentTrigger
{
	protected const EVENT_INITIATOR_ID = 'Initiator';
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	private const SELECT_ID = 'result_type';
	private const TYPE_ON_DONE = 'TYPE_ON_DONE';
	private const OPTION_VALUE_DEFAULT = 0;
	private const OPTION_VALUE_DONE = 1;
	private const OPTION_VALUE_STOPPED = 2;

	public static function getCode(): string
	{
		return 'B2E_COMPLETED';
	}

	public static function getName(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COMPLETED_NAME') ?? '';
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COMPLETED_DESCRIPTION') ?? '';
	}

	/**
	 * Completion and cancellation both name the user who acted, and that user already travels with the
	 * event in the released sign, so the initiator needs no version check here.
	 */
	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventInitiatorProperty(),
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COMPLETED_EVENT_DATE_TIME') ?? ''
				),
			],
			static::getSignReturnProperties()
		);
	}

	public function getReturnValues(): ?array
	{
		return array_merge(parent::getReturnValues() ?? [], [
			static::EVENT_INITIATOR_ID => $this->buildEventInitiatorValue(),
			static::EVENT_DATE_TIME_ID => static::buildEventDateTimeValue(),
		]);
	}

	protected static function getSignReturnFieldIds(): array
	{
		return [
			self::RETURN_SIGN_DOCUMENT_ID,
			self::RETURN_SIGN_MEMBER_ROLE,
			self::RETURN_SIGN_INITIATED_BY_TYPE,
			self::RETURN_SIGN_EVENT_TYPE,
			self::RETURN_SIGNER_USER,
			self::RETURN_SIGNER_NAME,
		];
	}

	protected static function getSignEventTypes(): array
	{
		return [
			EventData::TYPE_ON_DONE,
			EventData::TYPE_ON_STOPPED,
			EventData::TYPE_ON_CANCELED_BY_RESPONSIBILITY_PERSON,
			EventData::TYPE_ON_CANCELED_BY_REVIEWER,
			EventData::TYPE_ON_CANCELED_BY_EDITOR,
		];
	}

	public function checkApplyRules(array $trigger): bool
	{
		if (parent::checkApplyRules($trigger) === false)
		{
			return false;
		}

		$selectedValue = (int)($trigger['APPLY_RULES'][self::SELECT_ID] ?? self::OPTION_VALUE_DEFAULT);
		if ($selectedValue === self::OPTION_VALUE_DEFAULT)
		{
			return true;
		}

		$eventType = $this->inputData['eventType'] ?? '';

		return match ($selectedValue)
		{
			self::OPTION_VALUE_DONE => $eventType === self::TYPE_ON_DONE,
			self::OPTION_VALUE_STOPPED => $eventType !== self::TYPE_ON_DONE,
			default => false,
		};
	}

	protected static function getPropertiesMap(): array
	{
		return [
			[
				'Id' => self::SELECT_ID,
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COMPLETED_SELECT_TITLE'),
				'Type' => 'select',
				'EmptyValueText' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COMPLETED_OPTION_ALL'),
				'Options' => [
					[
						'value' => self::OPTION_VALUE_DONE,
						'name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COMPLETED_OPTION_SIGNED')
					],
					[
						'value' => self::OPTION_VALUE_STOPPED,
						'name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COMPLETED_OPTION_STOPPED')
					],
				],
			],
		];
	}

	public static function getNodeName(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COMPLETED_NODE_NAME') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COMPLETED_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::BLUE->value;
	}

	public static function getNodeIcon(): string
	{
		$iconValue = 'o-three-persons-check';
		$icon = Outline::tryFrom($iconValue);

		if ($icon !== null)
		{
			return $icon->name;
		}

		return parent::getNodeIcon();
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::SIGN->value];
	}
}

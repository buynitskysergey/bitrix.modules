<?php

namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

class WebFormTrigger extends BaseTrigger
{
	use ReturnUrlTrait;

	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	protected const RETURN_FORM_NAME = 'FormName';
	protected const RETURN_FORM_RESULT_ID = 'FormResultId';
	protected const RETURN_FORM_SOURCE_URL = 'FormSourceUrl';

	public static function getCode()
	{
		return 'WEBFORM';
	}

	public function setInputData($data)
	{
		parent::setInputData($data);

		if (is_callable([$this, 'setReturnValues']))
		{
			$this->setReturnValues([
				self::RETURN_FORM_NAME => (string)($this->getInputData('WEBFORM_NAME') ?? ''),
				self::RETURN_FORM_RESULT_ID => (int)($this->getInputData('WEBFORM_RESULT_ID') ?? 0),
				self::RETURN_FORM_SOURCE_URL => static::buildReturnUrlValue($this->getInputData('WEBFORM_SOURCE_URL')),
			]);
		}

		return $this;
	}

	/**
	 * The form is filled in by the client, often anonymously, so the node names no portal initiator.
	 */
	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_WEBFORM_EVENT_DATE_TIME') ?? ''
				),
			],
			static::getWebFormReturnProperties()
		);
	}

	public function getReturnValues(): ?array
	{
		return array_merge(parent::getReturnValues() ?? [], [
			static::EVENT_DATE_TIME_ID => static::buildEventDateTimeValue(),
		]);
	}

	/**
	 * Form fields shared with the feedback-form trigger, which lists them with a date/time of its own.
	 */
	protected static function getWebFormReturnProperties(): array
	{
		Loc::loadMessages(__FILE__);

		return [
			[
				'Id' => self::RETURN_FORM_NAME,
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_WEBFORM_RETURN_NAME'),
				'Type' => FieldType::STRING,
				'Default' => null,
			],
			[
				'Id' => self::RETURN_FORM_RESULT_ID,
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_WEBFORM_RETURN_RESULT_ID'),
				'Type' => FieldType::INT,
				'Default' => null,
			],
			[
				'Id' => self::RETURN_FORM_SOURCE_URL,
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_WEBFORM_RETURN_SOURCE_URL'),
				'Type' => FieldType::STRING,
				'Default' => null,
			],
		];
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_WEBFORM_NAME_1');
	}

	public function checkApplyRules(array $trigger)
	{
		if (!parent::checkApplyRules($trigger))
		{
			return false;
		}

		if (
			is_array($trigger['APPLY_RULES'])
			&& isset($trigger['APPLY_RULES']['form_id'])
			&& $trigger['APPLY_RULES']['form_id'] > 0
		)
		{
			return (int)$trigger['APPLY_RULES']['form_id'] === (int)$this->getInputData('WEBFORM_ID');
		}
		return true;
	}

	protected static function getPropertiesMap(): array
	{
		return [
			[
				'Id' => 'form_id',
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_WEBFORM_PROPERTY_FORM'),
				'Type' => 'select',
				'EmptyValueText' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_WEBFORM_DEFAULT_FORM'),
				'Options' => static::getFormList(),
			],
		];
	}

	public static function getGroup(): array
	{
		return ['other'];
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_WEBFORM_DESCRIPTION') ?? '';
	}

	protected static function getFormList(array $filter = []): array
	{
		$forms = \Bitrix\Crm\WebForm\Internals\FormTable::getDefaultTypeList([
			'select' => ['ID', 'NAME'],
			'order' => ['NAME' => 'ASC', 'ID' => 'ASC'],
			'filter' => $filter,
		])->fetchAll();

		return array_map(
			fn ($form) => ['value' => $form['ID'], 'name' => $form['NAME']],
			$forms
		);
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_WEBFORM_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::FORM->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::LEAD->value];
	}
}

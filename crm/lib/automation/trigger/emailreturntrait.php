<?php

namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Localization\Loc;

/**
 * Email-specific RETURN fields (subject / from / to) shared by all email triggers.
 * Values are read from the activity INPUT_DATA: SUBJECT and SETTINGS.EMAIL_META.
 *
 * Extension points {@see getEmailExtraReturnProperties()} / {@see buildEmailExtraReturnValues()}
 * let a concrete trigger append its own fields (e.g. the clicked link URL for EMAIL_LINK).
 *
 * The trait only describes the email fields. Each trigger lists them in its own getReturnProperties()
 * alongside the event fields it declares.
 */
trait EmailReturnTrait
{
	protected const RETURN_EMAIL_SUBJECT = 'EmailSubject';
	protected const RETURN_EMAIL_FROM = 'EmailFrom';
	protected const RETURN_EMAIL_TO = 'EmailTo';

	public function setInputData($data)
	{
		parent::setInputData($data);

		if (is_callable([$this, 'setReturnValues']))
		{
			$this->setReturnValues($this->buildEmailReturnValues());
		}

		return $this;
	}

	protected static function getEmailReturnProperties(): array
	{
		Loc::loadMessages(__FILE__);

		return array_merge(
			[
				[
					'Id' => self::RETURN_EMAIL_SUBJECT,
					'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_EMAIL_RETURN_SUBJECT'),
					'Type' => FieldType::STRING,
					'Default' => null,
				],
				[
					'Id' => self::RETURN_EMAIL_FROM,
					'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_EMAIL_RETURN_FROM'),
					'Type' => FieldType::STRING,
					'Default' => null,
				],
				[
					'Id' => self::RETURN_EMAIL_TO,
					'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_EMAIL_RETURN_TO'),
					'Type' => FieldType::STRING,
					'Default' => null,
				],
			],
			static::getEmailExtraReturnProperties()
		);
	}

	protected function buildEmailReturnValues(): array
	{
		$emailMeta = $this->getInputData('SETTINGS')['EMAIL_META'] ?? [];

		return array_merge(
			[
				self::RETURN_EMAIL_SUBJECT => (string)($this->getInputData('SUBJECT') ?? ''),
				self::RETURN_EMAIL_FROM => (string)($emailMeta['from'] ?? ''),
				self::RETURN_EMAIL_TO => (string)($emailMeta['to'] ?? ''),
			],
			$this->buildEmailExtraReturnValues()
		);
	}

	/**
	 * Trigger-specific RETURN descriptors appended to the shared email fields.
	 *
	 * @return array<int, array>
	 */
	protected static function getEmailExtraReturnProperties(): array
	{
		return [];
	}

	/**
	 * Trigger-specific RETURN values appended to the shared email values.
	 *
	 * @return array<string, mixed>
	 */
	protected function buildEmailExtraReturnValues(): array
	{
		return [];
	}
}

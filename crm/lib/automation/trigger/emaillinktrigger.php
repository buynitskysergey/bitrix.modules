<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Bizproc\FieldType;
Use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class EmailLinkTrigger extends BaseTrigger
{
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	use EmailReturnTrait;
	use ReturnUrlTrait;

	protected const RETURN_EMAIL_LINK_URL = 'EmailLinkUrl';

	public static function getCode()
	{
		return 'EMAIL_LINK';
	}

	/**
	 * The link is followed by the client, so the node names no portal initiator.
	 */
	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_LINKHOOK_EVENT_DATE_TIME') ?? ''
				),
			],
			static::getEmailReturnProperties()
		);
	}

	public function getReturnValues(): ?array
	{
		return array_merge(parent::getReturnValues() ?? [], [
			static::EVENT_DATE_TIME_ID => static::buildEventDateTimeValue(),
		]);
	}

	protected static function getEmailExtraReturnProperties(): array
	{
		return [
			[
				'Id' => self::RETURN_EMAIL_LINK_URL,
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_LINKHOOK_RETURN_URL'),
				'Type' => FieldType::STRING,
				'Default' => null,
			],
		];
	}

	protected function buildEmailExtraReturnValues(): array
	{
		return [
			self::RETURN_EMAIL_LINK_URL => static::buildReturnUrlValue($this->getInputData('URL')),
		];
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_LINKHOOK_NAME_1');
	}

	public function checkApplyRules(array $trigger)
	{
		if (!parent::checkApplyRules($trigger))
		{
			return false;
		}

		if (
			is_array($trigger['APPLY_RULES'])
			&& !empty($trigger['APPLY_RULES']['url'])
		)
		{
			$inputUrl = (string) $this->getInputData('URL');
			$triggerUrl = (string) $trigger['APPLY_RULES']['url'];

			return (mb_strpos($inputUrl, $triggerUrl) === 0);
		}
		return true;
	}

	protected static function getPropertiesMap(): array
	{
		return [
			[
				'Id' => 'url',
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_LINKHOOK_URL'),
				'Placeholder' => 'https://example.com',
				'Type' => 'text',
			]
		];
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_LINKHOOK_DESCRIPTION') ?? '';
	}

	public static function getGroup(): array
	{
		return ['clientCommunication'];
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_LINKHOOK_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::LINK->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::CLIENT_COMMUNICATION->value];
	}
}

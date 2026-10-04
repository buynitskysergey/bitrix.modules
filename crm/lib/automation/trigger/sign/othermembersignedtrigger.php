<?php
namespace Bitrix\Crm\Automation\Trigger\Sign;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class OtherMemberSignedTrigger extends InitiatorSignedTrigger
{
	public static function getCode()
	{
		return 'SIGN_OTHER_SIGNING';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_SIGN_OTHER_MEMBER_SIGNING_NAME_2');
	}

	public static function getGroup(): array
	{
		return ['paperwork'];
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_SIGN_OTHER_MEMBER_SIGNING_DESCRIPTION') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_SIGN_OTHER_MEMBER_SIGNING_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::PERSON_CHECKS->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::DOCUMENT_FLOW->value];
	}

	/**
	 * Same fields as the own-side signing node, but the date/time is the moment the counterpart signed.
	 */
	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_SIGN_OTHER_MEMBER_SIGNING_EVENT_DATE_TIME') ?? ''
				),
			],
			static::getSignReturnProperties()
		);
	}

	/**
	 * ON_SIGN of a counterpart names the member who signed, so the member fields are reported.
	 */
	protected static function getSignReturnFieldIds(): array
	{
		return [
			self::RETURN_SIGN_DOCUMENT_ID,
			self::RETURN_SIGN_MEMBER_ROLE,
			self::RETURN_SIGN_INITIATED_BY_TYPE,
			self::RETURN_SIGNER_USER,
			self::RETURN_SIGNER_NAME,
		];
	}
}

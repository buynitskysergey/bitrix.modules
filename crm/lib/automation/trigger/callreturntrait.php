<?php

namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Localization\Loc;

/**
 * Call-specific RETURN fields (phone number / direction / status) shared by the call triggers.
 * Direction is a normalized 'incoming' / 'outgoing' string so the field means the same
 * regardless of the source enum.
 *
 * A single catalog describes every field once; each concrete trigger lists the ids it actually
 * exposes via {@see getCallReturnFieldIds()} and adds {@see getCallReturnProperties()} to its own
 * getReturnProperties() next to the event fields it declares. Status is only available to the
 * incoming triggers:
 * voximplant raises those with a live VI\Call and passes its lifecycle status, while an outgoing
 * call is raised from the provider-agnostic Activity\Provider\Call::onAfterAdd(), which never sees
 * the call object, so the outgoing trigger does not declare a status field instead of exposing a
 * permanently empty one.
 */
trait CallReturnTrait
{
	protected const RETURN_CALL_PHONE = 'CallPhoneNumber';
	protected const RETURN_CALL_DIRECTION = 'CallDirection';
	protected const RETURN_CALL_STATUS = 'CallStatus';

	protected static function getCallReturnProperties(): array
	{
		Loc::loadMessages(__FILE__);

		$catalog = static::getCallReturnCatalog();
		$properties = [];
		foreach (static::getCallReturnFieldIds() as $id)
		{
			$properties[] = [
				'Id' => $id,
				'Name' => Loc::getMessage($catalog[$id]) ?? '',
				'Type' => FieldType::STRING,
				'Default' => null,
			];
		}

		return $properties;
	}

	/**
	 * Ids of the catalog fields this trigger exposes, in display order.
	 *
	 * @return string[]
	 */
	abstract protected static function getCallReturnFieldIds(): array;

	/**
	 * @return array<string, string> field id => localization message code
	 */
	private static function getCallReturnCatalog(): array
	{
		return [
			self::RETURN_CALL_PHONE => 'CRM_AUTOMATION_TRIGGER_CALL_RETURN_PHONE',
			self::RETURN_CALL_DIRECTION => 'CRM_AUTOMATION_TRIGGER_CALL_RETURN_DIRECTION',
			self::RETURN_CALL_STATUS => 'CRM_AUTOMATION_TRIGGER_CALL_RETURN_STATUS',
		];
	}

	protected function buildCallReturnValues(?string $phone, ?string $direction, ?string $status = null): array
	{
		$values = [
			self::RETURN_CALL_PHONE => (string)($phone ?? ''),
			self::RETURN_CALL_DIRECTION => (string)($direction ?? ''),
			self::RETURN_CALL_STATUS => (string)($status ?? ''),
		];

		return array_intersect_key($values, array_flip(static::getCallReturnFieldIds()));
	}
}

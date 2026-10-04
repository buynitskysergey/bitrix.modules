<?php

namespace Bitrix\Crm\Automation\Trigger\Sign;

use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Integration\CRM\Model\EventData;
use Bitrix\Sign\Type\Document\InitiatedByType;
use Bitrix\Sign\Type\Member\Role;

/**
 * Sign RETURN fields (document / member role / initiation type / event type / signer) shared by the
 * B2B and B2E signing triggers. A single catalog describes every field once; each trigger lists the
 * ids its own events fill via {@see getSignReturnFieldIds()}, and both the descriptors
 * {@see getSignReturnProperties()} and the values {@see buildSignReturnValues()} are built from that
 * list, so the sign part of the node cannot fall out of sync. The event fields the node declares are
 * listed by the node itself, next to these.
 *
 * The enumerations are SELECT fields, so the designer offers named values instead of internal codes.
 * The values stay the raw codes the sign module sends. Codes come from the sign module itself, hence
 * the options are empty while it is unavailable, a state in which the triggers are disabled anyway.
 *
 * The event type is only declared by the nodes more than one event can reach, listed by
 * {@see getSignEventTypes()}. On the remaining nodes the event is predefined by the trigger itself,
 * so there would be nothing to choose from.
 */
trait SignTriggerReturnDataTrait
{
	protected const RETURN_SIGN_DOCUMENT_ID = 'SignDocumentId';
	protected const RETURN_SIGN_MEMBER_ROLE = 'SignMemberRole';
	protected const RETURN_SIGN_INITIATED_BY_TYPE = 'SignInitiatedByType';
	protected const RETURN_SIGN_EVENT_TYPE = 'SignEventType';
	protected const RETURN_SIGNER_USER = 'SignerUser';
	protected const RETURN_SIGNER_NAME = 'SignerName';

	protected static function getSignReturnProperties(): array
	{
		Loc::loadMessages(__FILE__);

		$catalog = static::getSignReturnCatalog();
		$properties = [];
		foreach (static::getSignReturnFieldIds() as $id)
		{
			$field = $catalog[$id];
			$property = [
				'Id' => $id,
				'Name' => Loc::getMessage($field['name']) ?? '',
				'Type' => $field['type'],
				'Default' => $field['default'],
			];

			if ($field['type'] === FieldType::SELECT)
			{
				$property['Options'] = static::getSignReturnOptions($id);
			}

			$properties[] = $property;
		}

		return $properties;
	}

	/**
	 * Ids of the catalog fields this trigger exposes, in display order.
	 *
	 * @return string[]
	 */
	abstract protected static function getSignReturnFieldIds(): array;

	/**
	 * Event codes this trigger can be raised with, in any order. Empty unless the trigger declares
	 * the event type field.
	 *
	 * @return string[]
	 */
	protected static function getSignEventTypes(): array
	{
		return [];
	}

	/**
	 * @return array<string, array{type: string, name: string, default: mixed}>
	 */
	private static function getSignReturnCatalog(): array
	{
		return [
			self::RETURN_SIGN_DOCUMENT_ID => [
				'type' => FieldType::INT,
				'name' => 'CRM_AUTOMATION_TRIGGER_SIGN_RETURN_DOCUMENT_ID',
				'default' => 0,
			],
			self::RETURN_SIGN_MEMBER_ROLE => [
				'type' => FieldType::SELECT,
				'name' => 'CRM_AUTOMATION_TRIGGER_SIGN_RETURN_MEMBER_ROLE',
				'default' => '',
			],
			self::RETURN_SIGN_INITIATED_BY_TYPE => [
				'type' => FieldType::SELECT,
				'name' => 'CRM_AUTOMATION_TRIGGER_SIGN_RETURN_INITIATED_BY_TYPE',
				'default' => '',
			],
			self::RETURN_SIGN_EVENT_TYPE => [
				'type' => FieldType::SELECT,
				'name' => 'CRM_AUTOMATION_TRIGGER_SIGN_RETURN_EVENT_TYPE',
				'default' => '',
			],
			self::RETURN_SIGNER_USER => [
				'type' => FieldType::USER,
				'name' => 'CRM_AUTOMATION_TRIGGER_SIGN_RETURN_SIGNER_USER',
				'default' => null,
			],
			self::RETURN_SIGNER_NAME => [
				'type' => FieldType::STRING,
				'name' => 'CRM_AUTOMATION_TRIGGER_SIGN_RETURN_SIGNER_NAME',
				'default' => '',
			],
		];
	}

	/**
	 * @return array<string, string> code => localized name
	 */
	private static function getSignReturnOptions(string $id): array
	{
		if (!Loader::includeModule('sign'))
		{
			return [];
		}

		return match ($id)
		{
			self::RETURN_SIGN_MEMBER_ROLE => static::getSignMemberRoleOptions(),
			self::RETURN_SIGN_INITIATED_BY_TYPE => static::getSignInitiatedByTypeOptions(),
			self::RETURN_SIGN_EVENT_TYPE => static::getSignEventTypeOptions(),
			default => [],
		};
	}

	/**
	 * @return array<string, string>
	 */
	private static function getSignMemberRoleOptions(): array
	{
		return [
			Role::EDITOR => Loc::getMessage('CRM_AUTOMATION_TRIGGER_SIGN_ROLE_EDITOR') ?? '',
			Role::REVIEWER => Loc::getMessage('CRM_AUTOMATION_TRIGGER_SIGN_ROLE_REVIEWER') ?? '',
			Role::ASSIGNEE => Loc::getMessage('CRM_AUTOMATION_TRIGGER_SIGN_ROLE_ASSIGNEE') ?? '',
			Role::SIGNER => Loc::getMessage('CRM_AUTOMATION_TRIGGER_SIGN_ROLE_SIGNER') ?? '',
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function getSignInitiatedByTypeOptions(): array
	{
		return [
			InitiatedByType::COMPANY->value =>
				Loc::getMessage('CRM_AUTOMATION_TRIGGER_SIGN_INITIATED_BY_COMPANY') ?? ''
			,
			InitiatedByType::EMPLOYEE->value =>
				Loc::getMessage('CRM_AUTOMATION_TRIGGER_SIGN_INITIATED_BY_EMPLOYEE') ?? ''
			,
		];
	}

	/**
	 * Codes the trigger lists, ordered and named by the catalog below.
	 *
	 * @return array<string, string>
	 */
	private static function getSignEventTypeOptions(): array
	{
		$names = array_intersect_key(
			static::getSignEventTypeNames(),
			array_flip(static::getSignEventTypes())
		);

		return array_map(static fn(string $name): string => Loc::getMessage($name) ?? '', $names);
	}

	/**
	 * @return array<string, string> event code => localization message code
	 */
	private static function getSignEventTypeNames(): array
	{
		return [
			EventData::TYPE_ON_SIGNED_BY_EDITOR => 'CRM_AUTOMATION_TRIGGER_SIGN_EVENT_SIGNED_BY_EDITOR',
			EventData::TYPE_ON_SIGNED_BY_REVIEWER => 'CRM_AUTOMATION_TRIGGER_SIGN_EVENT_SIGNED_BY_REVIEWER',
			EventData::TYPE_ON_SIGNED_BY_EMPLOYEE => 'CRM_AUTOMATION_TRIGGER_SIGN_EVENT_SIGNED_BY_EMPLOYEE',
			EventData::TYPE_ON_STARTED => 'CRM_AUTOMATION_TRIGGER_SIGN_EVENT_STARTED',
			EventData::TYPE_ON_DONE => 'CRM_AUTOMATION_TRIGGER_SIGN_EVENT_DONE',
			EventData::TYPE_ON_STOPPED => 'CRM_AUTOMATION_TRIGGER_SIGN_EVENT_STOPPED',
			EventData::TYPE_ON_CANCELED_BY_RESPONSIBILITY_PERSON =>
				'CRM_AUTOMATION_TRIGGER_SIGN_EVENT_CANCELED_BY_RESPONSIBILITY_PERSON'
			,
			EventData::TYPE_ON_CANCELED_BY_REVIEWER => 'CRM_AUTOMATION_TRIGGER_SIGN_EVENT_CANCELED_BY_REVIEWER',
			EventData::TYPE_ON_CANCELED_BY_EDITOR => 'CRM_AUTOMATION_TRIGGER_SIGN_EVENT_CANCELED_BY_EDITOR',
		];
	}

	/**
	 * Values of the declared fields only: what the designer offers and what the workflow receives are
	 * the same list, so a field the node does not declare does not travel in RETURN either.
	 */
	protected static function buildSignReturnValues(array $data): array
	{
		$signerUserId = (int)($data['signerUserId'] ?? 0);

		$values = [
			self::RETURN_SIGN_DOCUMENT_ID => (int)($data['signDocumentId'] ?? 0),
			self::RETURN_SIGN_MEMBER_ROLE => (string)($data['signMemberRole'] ?? ''),
			self::RETURN_SIGN_INITIATED_BY_TYPE => (string)($data['signInitiatedByType'] ?? ''),
			self::RETURN_SIGN_EVENT_TYPE => (string)($data['eventType'] ?? ''),
			self::RETURN_SIGNER_USER => $signerUserId > 0 ? 'user_' . $signerUserId : null,
			self::RETURN_SIGNER_NAME => (string)($data['signerName'] ?? ''),
		];

		return array_intersect_key($values, array_flip(static::getSignReturnFieldIds()));
	}
}

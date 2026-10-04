<?php

namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Localization\Loc;

/**
 * Open-line RETURN fields (chat / channel / client / operator) shared by the open-line triggers.
 * Values are read from the session-derived INPUT_DATA scalars (CHAT_ID / CONFIG_ID / USER_ID /
 * OPERATOR_ID); trigger-specific values (e.g. answer time on the answer triggers) are kept via a
 * non-clobbering merge.
 *
 * A single catalog describes every field once; each concrete trigger lists the ids it actually
 * exposes via {@see getOpenLineReturnFieldIds()}, so declarations and values stay in sync, and adds
 * {@see getOpenLineReturnProperties()} to its own getReturnProperties() next to the event fields it
 * declares. The answer-control trigger has its own channel and operator fields (OpenLineAnswerCtrl*)
 * and skips the shared ones instead of offering the same value twice.
 *
 * {@see getOpenLineExtraReturnProperties()} / {@see buildOpenLineExtraReturnValues()} let a concrete
 * trigger append its own fields (e.g. the message text for OPENLINE_MSG).
 */
trait OpenLineReturnTrait
{
	protected const RETURN_OL_CHAT_ID = 'ChatId';
	protected const RETURN_OL_CONFIG_ID = 'ConfigId';
	protected const RETURN_OL_CLIENT_ID = 'ClientId';
	protected const RETURN_OL_OPERATOR_ID = 'OperatorId';

	/**
	 * Ids of the catalog fields this trigger exposes, in display order.
	 *
	 * @return string[]
	 */
	abstract protected static function getOpenLineReturnFieldIds(): array;

	/**
	 * @return array<string, array{type: string, inputKey: string, name: string}>
	 */
	private static function getOpenLineReturnCatalog(): array
	{
		return [
			self::RETURN_OL_CHAT_ID => [
				'type' => FieldType::INT,
				'inputKey' => 'CHAT_ID',
				'name' => 'CRM_AUTOMATION_TRIGGER_OPENLINE_RETURN_CHAT_ID',
			],
			self::RETURN_OL_CONFIG_ID => [
				'type' => FieldType::INT,
				'inputKey' => 'CONFIG_ID',
				'name' => 'CRM_AUTOMATION_TRIGGER_OPENLINE_RETURN_CONFIG_ID',
			],
			self::RETURN_OL_CLIENT_ID => [
				'type' => FieldType::USER,
				'inputKey' => 'USER_ID',
				'name' => 'CRM_AUTOMATION_TRIGGER_OPENLINE_RETURN_CLIENT_ID',
			],
			self::RETURN_OL_OPERATOR_ID => [
				'type' => FieldType::USER,
				'inputKey' => 'OPERATOR_ID',
				'name' => 'CRM_AUTOMATION_TRIGGER_OPENLINE_RETURN_OPERATOR_ID',
			],
		];
	}

	protected static function getOpenLineReturnProperties(): array
	{
		Loc::loadMessages(__FILE__);

		$catalog = static::getOpenLineReturnCatalog();
		$properties = [];
		foreach (static::getOpenLineReturnFieldIds() as $id)
		{
			$field = $catalog[$id];
			$properties[] = [
				'Id' => $id,
				'Name' => Loc::getMessage($field['name']) ?? '',
				'Type' => $field['type'],
				'Default' => null,
			];
		}

		return array_merge($properties, static::getOpenLineExtraReturnProperties());
	}

	protected function buildOpenLineReturnValues(): array
	{
		$catalog = static::getOpenLineReturnCatalog();
		$values = [];
		foreach (static::getOpenLineReturnFieldIds() as $id)
		{
			$field = $catalog[$id];
			$values[$id] = static::castOpenLineReturnValue($this->getInputData($field['inputKey']), $field['type']);
		}

		return array_merge($values, $this->buildOpenLineExtraReturnValues());
	}

	protected static function castOpenLineReturnValue($value, string $type)
	{
		return match ($type)
		{
			FieldType::USER => static::formatOpenLineUser($value),
			default => (int)$value,
		};
	}

	protected static function formatOpenLineUser($userId): ?string
	{
		$userId = (int)$userId;

		return $userId > 0 ? 'user_' . $userId : null;
	}

	/**
	 * @return array<int, array>
	 */
	protected static function getOpenLineExtraReturnProperties(): array
	{
		return [];
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function buildOpenLineExtraReturnValues(): array
	{
		return [];
	}
}

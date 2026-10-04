<?php

namespace Bitrix\Crm\Service;

use Bitrix\Crm\Category\ItemCategoryUserField;
use Bitrix\Crm\Entry\AddException;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;

final class EventHandler
{
	private const IBLOCK_BINDING_USER_FIELD_TYPES = [
		'iblock_section',
		'iblock_element',
	];
	private const IBLOCK_BINDING_ERROR_MESSAGE_CODE = 'CC_BLFE_ERR_IBLOCK_ELEMENT_BAD_IBLOCK_ID_MSGVER_1';
	private const CRM_USER_FIELD_ENTITY_PREFIX = 'CRM_';

	public static function onGetUserFieldTypeFactory(): array
	{
		return [
			ServiceLocator::getInstance()->get('crm.type.factory'),
		];
	}

	public static function OnBeforeUserTypeAdd(&$field): bool
	{
		$isCrmUserFieldByPrefix = self::isCrmUserFieldByPrefix($field);
		if ($isCrmUserFieldByPrefix)
		{
			$entityTypeId = \CCrmOwnerType::ResolveIDByUFEntityID($field['ENTITY_ID']);

			$ufAddRestriction = RestrictionManager::getUserFieldAddRestriction();
			if ($ufAddRestriction->isExceeded((int)$entityTypeId))
			{
				Container::getInstance()->getLocalization()->loadMessages();

				global $APPLICATION;
				$APPLICATION->ThrowException(Loc::getMessage('CRM_FEATURE_RESTRICTION_ERROR'));

				return false;
			}

			$resourceUfAddRestriction = RestrictionManager::getResourceBookingRestriction();
			if ($field['USER_TYPE_ID'] === 'resourcebooking' && !$resourceUfAddRestriction->hasPermission())
			{
				Container::getInstance()->getLocalization()->loadMessages();

				global $APPLICATION;
				$APPLICATION->ThrowException(Loc::getMessage('CRM_FEATURE_RESTRICTION_ERROR'));

				return false;
			}

			if (!self::isIBlockBindingUserFieldValid($field))
			{
				return false;
			}

			$categoryId = $field['CONTEXT_PARAMS']['CATEGORY_ID'] ?? 0; // if not set -> default category
			$fieldName = $field['FIELD_NAME'];
			if (isset($fieldName))
			{
				try
				{
					(new ItemCategoryUserField($entityTypeId))->add($categoryId, $fieldName);
				}
				catch (AddException $e)
				{
					global $APPLICATION;
					$APPLICATION->ThrowException($e->getMessage());

					return false;
				}
			}
		}
		elseif (self::isCrmUserField($field) && !self::isIBlockBindingUserFieldValid($field))
		{
			return false;
		}

		return true;
	}

	public static function OnBeforeUserTypeUpdate(&$field, int $id = 0): bool
	{
		if (!array_key_exists('SETTINGS', $field))
		{
			return true;
		}

		$userField = self::getUserFieldForUpdate($field, $id);
		if ($userField === null || !self::isCrmUserField($userField))
		{
			return true;
		}

		return self::isIBlockBindingUserFieldValid($userField);
	}

	private static function isCrmUserField(array $field): bool
	{
		$entityId = self::getUserFieldEntityId($field);
		if ($entityId === null)
		{
			return false;
		}

		return self::isCrmUserFieldByPrefix($field)
			|| (int)\CCrmOwnerType::ResolveIDByUFEntityID($entityId) > \CCrmOwnerType::Undefined
		;
	}

	private static function isCrmUserFieldByPrefix(array $field): bool
	{
		$entityId = self::getUserFieldEntityId($field);

		return $entityId !== null && str_starts_with($entityId, self::CRM_USER_FIELD_ENTITY_PREFIX);
	}

	private static function getUserFieldEntityId(array $field): ?string
	{
		$entityId = $field['ENTITY_ID'] ?? null;
		if (!is_string($entityId) || $entityId === '')
		{
			return null;
		}

		return $entityId;
	}

	private static function isIBlockBindingUserFieldValid(array $field): bool
	{
		if (!in_array($field['USER_TYPE_ID'] ?? null, self::IBLOCK_BINDING_USER_FIELD_TYPES, true))
		{
			return true;
		}

		$settings = self::getUserFieldSettings($field['SETTINGS'] ?? []);
		if ((int)($settings['IBLOCK_ID'] ?? 0) > 0)
		{
			return true;
		}

		self::throwIBlockBindingUserFieldError();

		return false;
	}

	private static function getUserFieldSettings($settings): array
	{
		if (is_array($settings))
		{
			return $settings;
		}

		if (!is_string($settings))
		{
			return [];
		}

		$unserializedSettings = @unserialize($settings, ['allowed_classes' => false]);

		return is_array($unserializedSettings) ? $unserializedSettings : [];
	}

	private static function getUserFieldForUpdate(array $field, int $id): ?array
	{
		$id = $id > 0 ? $id : (int)($field['ID'] ?? 0);
		if ($id <= 0)
		{
			return null;
		}

		$currentField = \Bitrix\Main\UserFieldTable::query()
			->setSelect(['ENTITY_ID', 'USER_TYPE_ID', 'SETTINGS'])
			->where('ID', $id)
			->setLimit(1)
			->fetch()
		;
		if (!is_array($currentField))
		{
			return null;
		}

		return array_merge($currentField, $field);
	}

	private static function throwIBlockBindingUserFieldError(): void
	{
		Loc::loadLanguageFile(dirname(__DIR__, 2) . '/install/components/bitrix/crm.config.fields.edit/component.php');

		global $APPLICATION;
		$APPLICATION->ThrowException(
			new \CAdminException([
				[
					'id' => 'IBLOCK_ID',
					'text' => (string)Loc::getMessage(self::IBLOCK_BINDING_ERROR_MESSAGE_CODE),
				],
			])
		);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\Trigger;

use Bitrix\Bizproc\Automation\Helper;

/**
 * Single source of truth for the tracked-fields property shared by CRM node-workflow
 * entity triggers: field-changed (CBPCrmEntityFieldChangedTrigger) and the edit trigger's
 * superset Fields control (CBPCrmEntityEditTrigger). Keeps the tracked-field options and
 * their ignore filters in one place, in parity with CategoryProperty.
 */
final class TrackedFieldsProperty
{
	public static function getOptions(string $document): array
	{
		$documentType = CategoryProperty::resolveDocumentTypeFromDocument($document);
		if (!$documentType)
		{
			return [];
		}

		$fields = Helper::getDocumentFields($documentType);
		if (!is_array($fields))
		{
			return [];
		}

		$filter = static function ($field) use ($document)
		{
			$id = $field['Id'];
			$type = $field['Type'];

			if (
				($document === \CCrmOwnerType::OrderName && str_starts_with($id, 'UF_'))
				|| str_contains($id, '.')
				|| str_starts_with($id, 'EVENT_')
				|| str_starts_with($id, 'PHONE_')
				|| str_starts_with($id, 'WEB_')
				|| str_starts_with($id, 'EMAIL_')
				|| str_starts_with($id, 'IM_')
				|| str_starts_with($id, 'LINK_')
				|| str_starts_with($id, 'UTM_')
				|| str_contains($id, 'OPPORTUNITY')
				|| str_contains($id, 'CURRENCY_ID')
				|| str_contains($id, 'ASSIGNED_BY')
				|| str_contains($id, 'RESPONSIBLE')
				|| str_contains($id, '_PRINTABLE')
				|| in_array($id, self::getIgnoredFieldIds(), true)
				|| in_array($type, self::getIgnoredFieldTypes(), true)
			)
			{
				return false;
			}

			return true;
		};

		$result = [];
		foreach (array_filter($fields, $filter) as $field)
		{
			$result[$field['Id']] = $field['Name'];
		}

		return $result;
	}

	private static function getIgnoredFieldIds(): array
	{
		return [
			'ID',
			'LEAD_ID',
			'DEAL_ID',
			'CONTACT_ID',
			'CONTACT_IDS',
			'COMPANY_ID',
			'COMPANY_IDS',
			'CREATED_BY_ID',
			'MODIFY_BY_ID',
			'DATE_CREATE',
			'DATE_MODIFY',
			'WEBFORM_ID',
			'STATUS_ID',
			'CATEGORY_ID',
			'ORIGINATOR_ID',
			'ORIGIN_ID',
			'XML_ID',
			'TAX_VALUE',
			'TAX_VALUE_ACCOUNT',
			'LAST_ACTIVITY_BY',
			'LAST_ACTIVITY_TIME',
			'IS_RECURRING',
			'MYCOMPANY_ID',
			'QUOTE_NUMBER',
			'TERMS',
			'LOCATION_ID',
			'EXCH_RATE',

			// address
			'ADDRESS',
			'ADDRESS_2',
			'ADDRESS_CITY',
			'ADDRESS_POSTAL_CODE',
			'ADDRESS_REGION',
			'ADDRESS_PROVINCE',
			'ADDRESS_COUNTRY',
			'ADDRESS_LOC_ADDR_ID',
			'FULL_ADDRESS',
			'ADDRESS_LEGAL',
			'BANKING_DETAILS',

			// not compatible
			'CRM_ID',
			'URL',
			'URL_BB',
			'TIME_CREATE',
			'PRODUCT_IDS',
			'TRACKING_SOURCE_ID',

			// not changed
			'CREATED_TIME',
			'CREATED_BY',
		];
	}

	private static function getIgnoredFieldTypes(): array
	{
		return ['phone', 'web', 'email', 'im', 'link'];
	}
}

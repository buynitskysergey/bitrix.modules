<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Bizproc\NodeFilter;

use Bitrix\Bizproc\Automation\Helper;

final class FilterFields
{
	public const FIELD_IDS = [
		'ID',
		'TITLE',
		'COLLECTION_ID',
		'PARENT_ID',
		'CREATED_BY',
		'UPDATED_BY',
		'CREATED_AT',
		'UPDATED_AT',
	];

	public static function forDocumentType(array $documentType): array
	{
		return array_values(self::mapForDocumentType($documentType));
	}

	public static function mapForDocumentType(array $documentType): array
	{
		$documentFields = Helper::getDocumentFields($documentType);

		$fields = [];
		foreach (self::FIELD_IDS as $fieldId)
		{
			if (isset($documentFields[$fieldId]))
			{
				$fields[$fieldId] = $documentFields[$fieldId];
			}
		}

		return $fields;
	}
}

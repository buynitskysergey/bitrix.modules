<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Crm\V2\Internal\Integration\Rest\DtoConverter;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoField;

class CustomFieldConverter
{
	public static function convertValueByDtoField(
		DtoField $dtoField,
		mixed $value,
		?string $publicPropertyName = null,
	): mixed
	{
		return DtoConverter::convertValueByDtoField($dtoField, $value, $publicPropertyName);
	}

	public static function convertValueToDto(string $type, mixed $value, string $parentPropertyName): Dto
	{
		return DtoConverter::convertValueToDto($type, $value, $parentPropertyName);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Rest\File;

use Bitrix\Crm\V2\Internal\Integration\Rest\DtoConverter;
use Bitrix\Rest\V3\Realisation\Dto\FileDto;

final class FileDtoConverter
{
	public static function convert(mixed $value, string $path): FileDto
	{
		/** @var FileDto $dto */
		$dto = DtoConverter::convertValueToDto(FileDto::class, $value, $path);

		return $dto;
	}
}

<?php

namespace Bitrix\Main\Infrastructure\Rest\Dto;

use Bitrix\Main\Infrastructure\Rest\Dto\Mapping\SmileSetMapper;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Dto\Dto;

#[MappedBy(SmileSetMapper::class)]
class SmileSetDto extends Dto
{
	public ?int $id;

	public ?int $parentId;

	public ?string $name;

	public ?string $type;
}

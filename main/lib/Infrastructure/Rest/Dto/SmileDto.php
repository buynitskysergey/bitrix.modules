<?php

namespace Bitrix\Main\Infrastructure\Rest\Dto;

use Bitrix\Main\Infrastructure\Rest\Dto\Mapping\SmileMapper;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Dto\Dto;

#[MappedBy(SmileMapper::class)]
class SmileDto extends Dto
{
	public ?int $id;

	public ?int $setId;

	public ?string $name;

	public ?string $image;

	public ?string $typing;

	public ?int $width;

	public ?int $height;

	public ?string $definition;
}

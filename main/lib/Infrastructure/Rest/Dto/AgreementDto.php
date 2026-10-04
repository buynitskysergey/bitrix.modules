<?php

namespace Bitrix\Main\Infrastructure\Rest\Dto;

use Bitrix\Main\Infrastructure\Rest\Dto\Mapping\AgreementMapper;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

#[MappedBy(AgreementMapper::class)]
class AgreementDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Filterable]
	public ?string $name;

	#[Filterable]
	public ?bool $active;

	#[Filterable]
	public ?string $languageId;

	public ?string $label;

	public ?string $text;

	public ?array $replace;
}

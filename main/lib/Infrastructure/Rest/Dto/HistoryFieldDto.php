<?php

namespace Bitrix\Main\Infrastructure\Rest\Dto;

use Bitrix\Main\Infrastructure\Rest\Dto\Mapping\HistoryFieldMapper;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

#[MappedBy(HistoryFieldMapper::class)]
class HistoryFieldDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Filterable]
	public ?int $historyId;

	#[Filterable]
	public ?string $field;

	public mixed $data;
}

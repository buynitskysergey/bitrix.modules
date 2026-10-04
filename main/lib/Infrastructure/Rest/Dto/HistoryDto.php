<?php

namespace Bitrix\Main\Infrastructure\Rest\Dto;

use Bitrix\Main\Infrastructure\Rest\Dto\Mapping\HistoryMapper;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

#[MappedBy(HistoryMapper::class)]
class HistoryDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Filterable]
	public ?int $userId;

	#[Filterable]
	public ?int $eventType;

	#[Filterable]
	public ?DateTime $dateInsert;

	#[Filterable]
	public ?string $remoteAddr;

	#[Filterable]
	public ?string $userAgent;

	#[Filterable]
	public ?string $requestUri;

	public ?int $updatedById;

	#[Filterable]
	public ?string $field;
}

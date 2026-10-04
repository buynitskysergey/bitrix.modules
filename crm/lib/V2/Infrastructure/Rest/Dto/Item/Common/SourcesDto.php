<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Crm\StatusTable;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\SourceDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\StatusIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

class SourcesDto extends Dto
{
	#[Editable]
	#[Filterable]
	#[Sortable]
	#[InArrayFrom(StatusIdProvider::class, ['statusTypeId' => StatusTable::ENTITY_ID_SOURCE], showValues: true)]
	public ?string $sourceId = null;
	#[RelationToOne('sourceId', 'id')]
	public ?SourceDto $source;

	#[Editable]
	#[Filterable]
	public ?string $sourceDescription = null;
}

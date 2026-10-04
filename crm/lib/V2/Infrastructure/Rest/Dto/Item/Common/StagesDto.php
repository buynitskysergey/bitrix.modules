<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\StageDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\StageSemanticDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\User\EmployeeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\StageIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

class StagesDto extends Dto
{
	#[Editable]
	#[Filterable]
	#[Sortable]
	#[InArrayFrom(StageIdProvider::class, showValues: true)]
	public ?string $stageId = null;
	#[RelationToOne('stageId', 'id')]
	public ?StageDto $stage;

	#[Filterable]
	#[Sortable]
	public ?DateTime $movedTime;
	#[Filterable]
	#[Sortable]
	public ?int $movedById;
	#[RelationToOne('movedById', 'id')]
	public ?EmployeeDto $movedBy;
	#[Filterable]
	#[Sortable]
	public bool $closed;
	#[Filterable]
	#[Sortable]
	public ?string $stageSemanticId;
	#[RelationToOne('stageSemanticId', 'id')]
	public ?StageSemanticDto $stageSemantic;
}

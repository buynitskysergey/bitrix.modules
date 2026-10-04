<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item;

use Bitrix\Crm\StatusTable;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\HonorificDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\StatusIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Length;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Main\Type\Date;

class LeadDto extends AbstractItemDto
{
	#[Editable]
	#[Filterable]
	#[Sortable]
	#[Required(['add'])]
	#[Length(max: 255)]
	public string $title;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[InArrayFrom(StatusIdProvider::class, ['statusTypeId' => StatusTable::ENTITY_ID_HONORIFIC], showValues: true)]
	public ?string $honorificId;

	#[RelationToOne('honorificId', 'id')]
	public ?HonorificDto $honorific;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[Length(max: 50)]
	public ?string $name;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[Length(max: 50)]
	public ?string $secondName;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[Length(max: 50)]
	public ?string $lastName;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[Length(max: 255)]
	public ?string $post;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[Length(max: 255)]
	public ?string $companyTitle;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public ?Date $birthdayTime;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public ?string $statusDescription;

}

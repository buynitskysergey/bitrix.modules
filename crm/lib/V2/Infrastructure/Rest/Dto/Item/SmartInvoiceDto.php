<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\LocationIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Length;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;

class SmartInvoiceDto extends AbstractItemDto
{
	#[Editable]
	#[Filterable]
	#[Sortable]
	#[Length(max: 255)]
	public ?string $xmlId;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[Length(max: 255)]
	public string $title;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public ?string $accountNumber;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[InArrayFrom(LocationIdProvider::class)]
	public ?int $locationId;
}

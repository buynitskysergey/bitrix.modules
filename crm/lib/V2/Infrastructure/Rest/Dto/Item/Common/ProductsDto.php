<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\CurrencyDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\CurrencyIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Min;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

class ProductsDto extends Dto
{
	#[Editable]
	#[Filterable]
	#[Sortable]
	#[Min(0)]
	public ?float $opportunity;
	#[Filterable]
	#[Sortable]
	public ?float $taxValue;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public ?bool $isManualOpportunity;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[InArrayFrom(CurrencyIdProvider::class, showValues: true)]
	public ?string $currencyId;
	#[RelationToOne('currencyId', 'id')]
	public ?CurrencyDto $currency;
}

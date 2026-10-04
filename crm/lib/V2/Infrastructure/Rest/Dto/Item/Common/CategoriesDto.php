<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\CategoryDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\CategoryIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

class CategoriesDto extends Dto
{
	#[Editable]
	#[Filterable]
	#[Sortable]
	#[InArrayFrom(CategoryIdProvider::class)]
	public ?int $categoryId;
	#[RelationToOne('categoryId', 'id')]
	public ?CategoryDto $category;
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Dto\Dto;

class MultifieldDto extends Dto
{
	public ?int $id;

	#[Editable]
	#[Filterable]
	#[Required(['add', 'update'])]
	#[NotEmpty(allowZero: true)]
	public string $valueTypeId;

	#[RelationToOne('valueTypeId', 'id')]
	public ?MultifieldValueTypeDto $valueType;

	#[Editable]
	#[Filterable]
	#[Required(['add', 'update'])]
	#[NotEmpty(allowZero: true)]
	public string $value;
}

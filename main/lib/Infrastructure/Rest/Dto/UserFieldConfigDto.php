<?php

namespace Bitrix\Main\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

class UserFieldConfigDto extends Dto
{
	#[Filterable]
	#[Sortable]
	public ?int $id;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public ?string $entityId;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public ?string $fieldName;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public ?string $userTypeId;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public ?string $xmlId;

	#[Editable]
	#[Sortable]
	public ?int $sort;

	#[Editable]
	#[Filterable]
	public ?bool $multiple;

	#[Editable]
	#[Filterable]
	public ?bool $mandatory;

	#[Editable]
	#[Filterable]
	public ?string $showFilter;

	#[Filterable]
	public ?bool $showInList;

	#[Editable]
	#[Filterable]
	public ?bool $editInList;

	#[Editable]
	#[Filterable]
	public ?bool $isSearchable;

	#[Editable]
	public mixed $settings;

	#[Editable]
	public ?array $editFormLabel;

	public ?array $listColumnLabel;

	public ?array $listFilterLabel;

	public ?array $errorMessage;

	#[Editable]
	public ?array $helpMessage;

	#[Editable]
	public ?array $enum;
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Category;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Length;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The external contract of a category (pipeline) of a CRM entity type.
 *
 * Declared here is the widest shape the contract can take. Which of the fields a particular entity
 * type actually publishes, and which of them it accepts on a write, is decided by
 * {@see CategoryDtoGenerator} from the category field model of that entity type: a Deal category has
 * no `isSystem` / `code` at all and keeps `isDefault` read-only, while a Company one has all three
 * and accepts `isDefault`.
 *
 * Nothing here is filterable or sortable: the category provider of the domain reads the whole
 * visible set in the order the domain keeps it and takes neither a filter nor a sort, so publishing
 * either would promise what the scenario does not do.
 */
abstract class AbstractCategoryDto extends Dto
{
	/**
	 * `0` is the default category of Deal, Contact and Company - a real identifier rather than a
	 * missing one.
	 */
	public int $id;

	/**
	 * Spelled the way the stage contract spells it
	 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Category\StageDto::$name}). The rules of this field
	 * are carried over to the contract of every entity type by {@see CategoryDtoGenerator}, which
	 * exports them and reads them back - the reason the rule of the module is used rather than the one
	 * of the kernel.
	 */
	#[Editable]
	#[Required(['add'])]
	#[NotEmpty(allowZero: true, allowSpaces: true)]
	#[Length(max: 100)]
	public string $name;

	#[Editable]
	public int $sort;

	#[Editable]
	public bool $isDefault;

	public ?bool $isSystem;

	public ?string $code;
}

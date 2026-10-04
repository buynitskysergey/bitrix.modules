<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Category;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Length;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\RegExp;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * One stage of a group replacement as the caller sends it.
 *
 * A stage is the same stage the category already holds when the caller sends back the identifier it
 * is known by; a stage sent without one is created. That is the whole reason this contract is not
 * {@see StageDto}: there the identifier is read-only, here its absence is what asks for a stage.
 *
 * Neither the semantics, nor the position, nor the system flag of a stage belong here. The order of
 * the stages is the order of the elements themselves, and what the semantics of the set are is the
 * domain's to decide, not the replacement's - declaring the fields would promise a contract the
 * scenario does not carry out, and the domain refuses one that is spelled out anyway
 * ({@see \Bitrix\Crm\V2\Public\Command\Category\ReplaceStagesCommand}). The category is named once
 * by the request rather than by every element of it.
 *
 * A missing colour leaves the stored one alone; an empty one drops it.
 */
final class StageInputDto extends Dto
{
	#[Editable]
	public ?string $stageId;

	/** Spelled the way the single-stage contract spells it ({@see StageDto::$name}). */
	#[Editable]
	#[Required]
	#[NotEmpty(allowZero: true, allowSpaces: true)]
	#[Length(max: StageDto::NAME_MAX_LENGTH)]
	public string $name;

	#[Editable]
	#[RegExp(StageDto::COLOR_PATTERN)]
	public string $color;
}

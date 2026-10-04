<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\Mapping\AgentBlockMapper;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * Block of the agent-facing workflow graph (DTO-01).
 *
 * Property order mirrors AgentBlock::toArray(). Conditionally emitted fields (rules, returnProperties,
 * the frame overlay trio) are left uninitialized unless the domain entity emits them, while description
 * and presetId are always emitted, even as null - exactly as the domain does.
 */
#[MappedBy(AgentBlockMapper::class)]
class AgentBlockDto extends Dto
{
	#[Editable(['add'])]
	public string $type;

	#[Editable(['add'])]
	public string $title;

	#[Editable(['add'])]
	public string $id;

	#[ElementType(AgentSettingDto::class)]
	#[Editable(['add'])]
	public DtoCollection $settings;

	#[Editable(['add'])]
	public ?string $description;

	#[Editable(['add'])]
	public ?string $presetId;

	/** Untyped on purpose: the keys are port ids of a complex node, so there is no fixed schema. */
	#[Editable(['add'])]
	public mixed $rules;

	#[Description('Output properties of the block. Read-only: comes when the template is read back, and sending it refuses the request.')]
	public mixed $returnProperties;

	/** @var list<string> */
	#[Editable(['add'])]
	public array $memberBlockIds;

	#[Editable(['add'])]
	public ?string $frameColorName;

	#[Editable(['add'])]
	public ?string $frameContent;
}

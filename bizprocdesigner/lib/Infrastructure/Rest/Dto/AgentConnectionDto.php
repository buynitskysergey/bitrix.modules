<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\Mapping\AgentConnectionMapper;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Connection between two blocks of the agent-facing graph (DTO-01).
 *
 * Property order mirrors AgentConnection::toArray(); port ids are emitted only when the domain
 * entity emits them, so an unset property is what keeps them out of the payload.
 */
#[MappedBy(AgentConnectionMapper::class)]
class AgentConnectionDto extends Dto
{
	#[Editable(['add'])]
	public string $destinationBlockId;

	#[Editable(['add'])]
	public string $sourceBlockId;

	#[Editable(['add'])]
	public ?string $sourcePortId;

	#[Editable(['add'])]
	public ?string $targetPortId;
}

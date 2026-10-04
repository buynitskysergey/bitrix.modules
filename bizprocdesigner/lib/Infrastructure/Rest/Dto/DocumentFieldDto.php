<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\Mapping\DocumentFieldMapper;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Document field the agent can address in a workflow graph.
 *
 * Property order mirrors DocumentField::toArray(); options are emitted only when the domain entity
 * emits them, so a field without a value list keeps the key out of the payload. Read-only: the field
 * set follows the document type the template is bound to.
 */
#[MappedBy(DocumentFieldMapper::class)]
class DocumentFieldDto extends Dto
{
	public string $id;

	public string $name;

	public bool $editable;

	#[Description('Value list of the field. Comes only for a field that has one.')]
	public array $options;
}

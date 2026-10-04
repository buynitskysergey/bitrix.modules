<?php

declare(strict_types=1);

namespace Bitrix\Rest\V3\Realisation\Dto;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\Internal\Model\DeferredBatchTable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\ResolvedBy;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Realisation\Controller\DeferredBatch;

#[ResolvedBy(DeferredBatch::class)]
#[OrmEntity(DeferredBatchTable::class)]
class DeferredBatchDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Filterable, Sortable]
	public string $status;

	#[Required(['add'])]
	#[NotEmpty]
	public array $commands;

	#[Sortable]
	public ?DateTime $createdAt;

	#[Sortable]
	public ?DateTime $updatedAt;

	/** ID of the result file in b_file (set when status = done). */
	public ?int $resultFileId;

	/** Human-readable error details (only set when status = error). */
	public ?string $errorMessage;
}

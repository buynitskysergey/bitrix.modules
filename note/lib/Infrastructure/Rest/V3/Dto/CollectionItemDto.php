<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Dto;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Note\Infrastructure\Rest\V3\Dto\Mapping\CollectionMapper;
use Bitrix\Note\Internal\Model\CollectionTable;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

#[MappedBy(CollectionMapper::class)]
class CollectionItemDto extends Dto
{
	public ?int $id;

	// Non-nullable on purpose: a collection never answers with an empty name, and a nullable property
	// would let an explicit null through - the parser short-circuits before NotEmpty is ever asked.
	#[Required(['add', 'update'])]
	#[Editable(['add', 'update'])]
	#[NotEmpty]
	#[Length(max: CollectionTable::MAX_NAME_LENGTH)]
	public string $name;

	#[Editable(['add'])]
	public ?int $position;

	// Who the collection is open to: "private" or "portal". Read out of the access rows, never out of
	// the POLICY_LEVEL column of the collection - nothing writes to that column.
	public ?string $policyLevel;

	// What the caller may do with this collection: "none" | "view" | "edit" | "full". Read-only, and
	// about the collection alone - a document inside it may be granted separately and higher.
	public ?string $accessLevel;

	// Read-only marker of the archive: an archived collection still answers reads, but every write into
	// it is refused, and without this field the client cannot tell the caller why.
	public ?bool $isArchived;
	public ?int $createdBy;
	// Dates are carried as date objects, not strings: the canon serializes them to DATE_ATOM itself.
	public ?DateTime $createdAt;
	public ?int $updatedBy;
	public ?DateTime $updatedAt;

	// Read-only (no #[Editable]): the collection's knowledge base description, i.e. the raw
	// MARKDOWN of its main document. Loaded unconditionally by the mapper (the field is
	// VIEW-gated at the query level, not by a feature flag); non-empty markdown yields the
	// string, empty or absent markdown yields null. Writing the description via REST is out
	// of scope.
	public ?string $markdownDescription;
}

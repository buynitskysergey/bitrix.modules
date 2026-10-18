<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Dto;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Note\Infrastructure\Rest\V3\Dto\Mapping\DocumentMapper;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

#[MappedBy(DocumentMapper::class)]
class DocumentItemDto extends Dto
{
	public ?int $id;

	#[Required(['add'])]
	#[Editable(['add'])]
	public ?int $collectionId;

	#[Editable(['add'])]
	public ?int $parentId;

	// Non-nullable on purpose: neither field ever answers with null, and a nullable property would let
	// an explicit null through - the parser short-circuits before NotEmpty is ever asked, so a null
	// markdown used to wipe the document quietly.
	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[NotEmpty]
	#[Length(max: DocumentTable::MAX_TITLE_LENGTH)]
	public string $title;

	#[Editable(['add', 'update'])]
	public string $markdown;

	public ?int $position;
	public ?int $createdBy;

	// Structural change of the document record (rename, move, archive, REST overwrite). An edit in
	// the collaborative editor deliberately moves neither: it reports through contentUpdatedAt.
	public ?int $updatedBy;
	// Dates are carried as date objects, not strings: the canon serializes them to DATE_ATOM itself.
	public ?DateTime $createdAt;
	public ?DateTime $updatedAt;

	// Read-only (no #[Editable]): moment the returned markdown was built. Null until materialized.
	public ?DateTime $contentUpdatedAt;

	// State markers. Non-nullable on purpose: without them a client cannot tell an archived or a
	// trashed document from a live one, and the whole point of showing those states over REST is that
	// the client knows what it is looking at. Read-only, so being non-nullable never makes them
	// required on input.
	public bool $isArchived;
	public bool $isTrashed;

	// Only meaningful together with isTrashed: the collection that owned the document is gone.
	public bool $isOrphan;
}

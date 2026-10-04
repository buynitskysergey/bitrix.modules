<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Entity\History;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

/**
 * A content snapshot (b_note_document_version), created on compact and on
 * OverwriteDocumentContentCommand (restore/REST-overwrite). Holds only a MARKDOWN +
 * TITLE snapshot — no YJS_STATE/CONTENT_FORMAT/TRIGGER_TYPE.
 */
final class DocumentVersion implements EntityInterface
{
	public function __construct(
		private readonly ?int $id,
		private readonly int $documentId,
		private readonly string $markdown,
		private readonly string $title,
		private readonly int $createdBy,
		private readonly DateTime $createdAt,
	) {}

	public static function create(int $documentId, string $markdown, string $title, int $createdBy): self
	{
		return new self(null, $documentId, $markdown, $title, $createdBy, new DateTime());
	}

	public function withId(int $id): self
	{
		return new self($id, $this->documentId, $this->markdown, $this->title, $this->createdBy, $this->createdAt);
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getDocumentId(): int
	{
		return $this->documentId;
	}

	public function getMarkdown(): string
	{
		return $this->markdown;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getCreatedBy(): int
	{
		return $this->createdBy;
	}

	public function getCreatedAt(): DateTime
	{
		return $this->createdAt;
	}
}

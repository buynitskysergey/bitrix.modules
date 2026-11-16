<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Dto\ContentList;

/**
 * One segment of the path shown on a search card and above the title inside a node. The
 * first segment is the knowledge base itself, the rest are ancestor documents.
 *
 * A segment the viewer has no access to is never built: the path starts at the first reachable
 * segment and nothing marks the gap. A name alone already tells that an object exists, so a muted
 * but readable placeholder would leak exactly what the permission withholds.
 */
final class PathSegmentDto implements \JsonSerializable
{
	public const KIND_COLLECTION = 'collection';
	public const KIND_DOCUMENT = 'document';

	private function __construct(
		public readonly string $kind,
		public readonly int $id,
		public readonly string $title,
		public readonly ContentState $state,
	) {}

	public static function collection(int $collectionId, string $title, ContentState $state): self
	{
		return new self(self::KIND_COLLECTION, $collectionId, $title, $state);
	}

	public static function document(int $documentId, string $title, ContentState $state): self
	{
		return new self(self::KIND_DOCUMENT, $documentId, $title, $state);
	}

	public function jsonSerialize(): array
	{
		return [
			'kind' => $this->kind,
			'id' => $this->id,
			'title' => $this->title,
			'state' => $this->state->value,
		];
	}
}

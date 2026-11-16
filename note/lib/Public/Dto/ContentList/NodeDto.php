<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Dto\ContentList;

/**
 * One card of a content-area list, whatever the surface: the recycle bin, the archive, the
 * search, the level of a live tree and the list inside an opened node all answer with this shape.
 *
 * "Has children" and "how many children" are separate fields on purpose: the first decides whether
 * the expand button is drawn at all, the second is printed on the card. The count is direct children
 * in the same state as the node, not the whole branch - walking a branch per card on MySQL 5.6 is
 * too expensive, and the number matches how many cards the first expansion opens.
 */
final class NodeDto implements \JsonSerializable
{
	/**
	 * @param PathSegmentDto[]|null $path filled in the search and inside an opened node; null in the
	 *        recycle bin and the archive, where the section header already says where the card is from
	 * @param array{id: int, name: string, photoUrl: ?string, color: string, isSystem?: true}|null $author
	 *        the author as the flat listings resolved him - name, avatar and the identity colour
	 *        computed on the server, so the client never recomputes it
	 * @param int|null $recycleBinEntryId the row of the bin this card stands for. Restoring and
	 *        deleting for good address that row and not the document, so a card read from the bin
	 *        carries it. Null everywhere else - and null inside a deleted knowledge base too: its
	 *        content never moved into the bin, so those cards have no row of their own and return
	 *        with the base from the header of the section.
	 * @param string $snippet the search hit with the matches wrapped in `mark`, already escaped by
	 *        the extractor that built it: the card prints it as markup, while `excerpt` is plain
	 *        text and is printed as text. Empty on every surface but the search.
	 */
	public function __construct(
		public readonly int $documentId,
		public readonly string $title,
		public readonly string $excerpt,
		public readonly int $authorId,
		public readonly string $changedAt,
		public readonly ContentState $state,
		public readonly int $childCount,
		public readonly bool $hasChildren,
		public readonly ?array $path = null,
		public readonly bool $canRestore = false,
		public readonly bool $canHardDelete = false,
		public readonly ?int $recycleBinEntryId = null,
		public readonly ?array $author = null,
		public readonly string $snippet = '',
	) {}

	public function jsonSerialize(): array
	{
		return [
			'documentId' => $this->documentId,
			'recycleBinEntryId' => $this->recycleBinEntryId,
			'title' => $this->title,
			'excerpt' => $this->excerpt,
			'snippet' => $this->snippet,
			'authorId' => $this->authorId,
			'author' => $this->author,
			'changedAt' => $this->changedAt,
			'state' => $this->state->value,
			'childCount' => $this->childCount,
			'hasChildren' => $this->hasChildren,
			'path' => $this->path === null
				? null
				: array_map(static fn(PathSegmentDto $segment): array => $segment->jsonSerialize(), $this->path),
			'canRestore' => $this->canRestore,
			'canHardDelete' => $this->canHardDelete,
		];
	}
}

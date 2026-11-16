<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Dto\ContentList;

/**
 * One section of a content-area list: a knowledge base and how many top-level nodes it
 * holds. One shape for the recycle bin, the archive and the search - the three sources under it
 * differ, the answer does not, so the client keeps one model.
 *
 * The section carries no key of its own level: the level is asked for from the beginning whichever
 * way it is opened, so a key here would only be a page of rows fetched on every list open and read
 * by nobody.
 *
 * The counter is reported capped: anything above the threshold draws as overflow, which matches
 * the counter component, whose maxValue is 99 and which draws "99+" by itself.
 */
final class SectionDto implements \JsonSerializable
{
	/** Highest number reported as itself; anything from COUNT_LIMIT + 1 up is overflow. */
	public const COUNT_LIMIT = 99;

	/**
	 * Title of the technical section. It is a localization key, not a phrase: there is no knowledge
	 * base under this section to take a name from, and the caption belongs to the client's own
	 * phrase set.
	 */
	public const TECHNICAL_TITLE_KEY = 'NOTE_CONTENT_LIST_SECTION_NO_COLLECTION';

	private function __construct(
		public readonly ?int $collectionId,
		public readonly string $title,
		public readonly ContentState $state,
		public readonly int $count,
		public readonly bool $countOverflow,
		public readonly ?array $trashedBy = null,
		public readonly ?string $trashedAt = null,
	) {}

	/**
	 * @param int $rawCount nodes counted, possibly beyond the threshold; clamped here
	 * @param array{id: int, name: string, photoUrl: ?string, color: string, isSystem?: true}|null $trashedBy
	 *   who sent the base to the bin, in the same shape every other card of the module names a person
	 * @param string|null $trashedAt when they did, ISO 8601
	 */
	public static function forCollection(
		int $collectionId,
		string $title,
		ContentState $state,
		int $rawCount,
		?array $trashedBy = null,
		?string $trashedAt = null,
	): self
	{
		// A deleted base is the only one with a deletion to report; the archive shows no date of its
		// own today, so for every other state the two fields are dropped rather than passed through.
		$isTrashed = $state === ContentState::Trashed;

		return new self(
			collectionId: $collectionId,
			title: $title,
			state: $state,
			count: min($rawCount, self::COUNT_LIMIT),
			countOverflow: $rawCount > self::COUNT_LIMIT,
			trashedBy: $isTrashed ? $trashedBy : null,
			trashedAt: $isTrashed ? $trashedAt : null,
		);
	}

	/**
	 * Technical "no knowledge base" section: documents left behind by a deleted knowledge base, and
	 * documents whose base name is hidden by permissions.
	 */
	public static function withoutCollection(int $rawCount): self
	{
		return new self(
			collectionId: null,
			title: self::TECHNICAL_TITLE_KEY,
			state: ContentState::Live,
			count: min($rawCount, self::COUNT_LIMIT),
			countOverflow: $rawCount > self::COUNT_LIMIT,
		);
	}

	public function jsonSerialize(): array
	{
		return [
			'collectionId' => $this->collectionId,
			'title' => $this->title,
			'state' => $this->state->value,
			'count' => $this->count,
			'countOverflow' => $this->countOverflow,
			'trashedBy' => $this->trashedBy,
			'trashedAt' => $this->trashedAt,
		];
	}
}

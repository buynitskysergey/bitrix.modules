<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Read;

/**
 * What a read returns: the entities of one page and the cursor of the next one.
 *
 * The cursor is honest - it is filled only when a row beyond the page was actually fetched, so a
 * client that follows it always gets something. That is why the reads ask for one row more than the
 * page holds: without it "is there more" can only be guessed.
 */
final class ReadResult
{
	/**
	 * @param object[] $items
	 * @param array<string, int>|null $nextCursor
	 */
	public function __construct(
		public readonly array $items = [],
		public readonly ?array $nextCursor = null,
	) {}

	/**
	 * @param object[] $rows rows fetched with {@see CollectionReadQuery::fetchLimit()}
	 * @param \Closure(object): array<string, int> $cursorOf keyset of the last row of the page
	 */
	public static function paginate(array $rows, int $limit, \Closure $cursorOf): self
	{
		$hasMore = count($rows) > $limit;
		if (!$hasMore)
		{
			return new self($rows);
		}

		$page = array_slice($rows, 0, $limit);
		$last = end($page);

		return new self($page, $last === false ? null : $cursorOf($last));
	}
}

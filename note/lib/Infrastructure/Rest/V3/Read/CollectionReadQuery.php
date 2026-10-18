<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Read;

use Bitrix\Note\Public\Provider\CollectionProvider;

/**
 * The form of a request to read collections: fields, visibility, page.
 *
 * Visibility is a resolved list of identifiers rather than a condition: the level of a collection is
 * not stored on its row, it is assembled from the access table, so the only way to say "these are
 * visible" is to name them. `null` means the caller is not restricted at all - the portal
 * administrator, for whom no predicate is built.
 *
 * The order is not carried here: the keyset of this list is (POSITION, ID) descending, and the
 * repository fixes it. An order the cursor cannot express would break the page, not widen it.
 */
final class CollectionReadQuery
{
	/** @var string[] */
	public readonly array $select;

	/** @var int[]|null */
	public readonly ?array $visibleIds;

	public readonly int $limit;

	/** @var array{position: int, id: int}|null */
	public readonly ?array $cursor;

	/**
	 * @param string[] $select names of DTO fields; an empty list means the default set
	 * @param int[]|null $visibleIds null for an unrestricted caller, [] for one who sees nothing
	 */
	public function __construct(
		array $select = [],
		?array $visibleIds = null,
		int $limit = 0,
		?array $cursor = null,
	)
	{
		$this->select = $select;
		$this->visibleIds = $visibleIds;
		$this->limit = $limit > 0 ? min($limit, CollectionProvider::MAX_LIMIT) : CollectionProvider::DEFAULT_LIMIT;
		$this->cursor = self::normalizeCursor($cursor);
	}

	public function fetchLimit(): int
	{
		return $this->limit + 1;
	}

	public function afterPosition(): ?int
	{
		return $this->cursor['position'] ?? null;
	}

	public function afterId(): ?int
	{
		return $this->cursor['id'] ?? null;
	}

	/**
	 * @return array{position: int, id: int}|null
	 */
	private static function normalizeCursor(?array $cursor): ?array
	{
		if (!isset($cursor['position'], $cursor['id']))
		{
			return null;
		}

		$id = (int)$cursor['id'];

		// A cursor without a real row behind it is not a page start - the whole list is answered instead.
		return $id > 0 ? ['position' => (int)$cursor['position'], 'id' => $id] : null;
	}
}

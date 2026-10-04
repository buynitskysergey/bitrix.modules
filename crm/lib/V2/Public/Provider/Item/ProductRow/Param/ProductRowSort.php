<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item\ProductRow\Param;

use Bitrix\Crm\V2\Public\Provider\Item\Exception\UnknownSortFieldException;
use Bitrix\Main\Provider\Params\Sort;

/**
 * Caller-side spec for the ORDER BY clause of ProductRow reads.
 *
 * The set of sortable fields is closed - {@see FIELD_SORT} (the row order inside its owner) and
 * {@see FIELD_ID}. A field outside it is rejected rather than dropped: publishing a field in the
 * response does not make it sortable.
 *
 * The primary key is always appended as a tie-breaker, so equally sorted rows keep one order across
 * pages. An empty sort therefore means the natural row order: {@see FIELD_SORT}, then the primary key.
 */
final class ProductRowSort extends Sort
{
	public const FIELD_SORT = 'sort';
	public const FIELD_ID = 'id';

	/**
	 * @param array<string, string> $sort `field => 'ASC'|'DESC'` (case-insensitive direction).
	 * @throws UnknownSortFieldException When a field is outside the closed sortable list.
	 */
	public function __construct(array $sort = [])
	{
		parent::__construct([]);

		foreach ($sort as $field => $direction)
		{
			$this->by((string)$field, (string)$direction);
		}
	}

	/**
	 * @throws UnknownSortFieldException When the field is outside the closed sortable list.
	 */
	public function by(string $field, string $direction = 'ASC'): static
	{
		if (!in_array($field, $this->getAllowedFields(), true))
		{
			throw new UnknownSortFieldException($field);
		}

		$this->sort[$field] = strcasecmp($direction, 'DESC') === 0 ? 'DESC' : 'ASC';

		return $this;
	}

	/**
	 * The ordering to read by: the caller's fields in the order given, plus the primary-key
	 * tie-breaker. Never empty.
	 *
	 * @return array<string, string>
	 */
	public function getRaw(): array
	{
		$sort = empty($this->sort) ? [self::FIELD_SORT => 'ASC'] : $this->sort;
		if (!isset($sort[self::FIELD_ID]))
		{
			$sort[self::FIELD_ID] = 'ASC';
		}

		return $sort;
	}

	/**
	 * Implements {@see \Bitrix\Main\Provider\Params\SortInterface}. Same ordering as
	 * {@see getRaw()} - the tie-breaker holds on every path.
	 *
	 * @return array<string, string>
	 */
	public function prepareSort(): array
	{
		return $this->getRaw();
	}

	/**
	 * @return string[]
	 */
	protected function getAllowedFields(): array
	{
		return [self::FIELD_SORT, self::FIELD_ID];
	}
}

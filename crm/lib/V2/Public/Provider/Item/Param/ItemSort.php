<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item\Param;

use Bitrix\Main\Provider\Params\Sort;

/**
 * Caller-side spec for the ORDER BY clause of Provider read methods. Holds a camelCase
 * `field => 'ASC'|'DESC'` map; the Provider resolves names to ORM columns.
 */
final class ItemSort extends Sort
{
	/**
	 * @param array<string, string> $sort `camelName => 'ASC'|'DESC'` (case-insensitive direction).
	 */
	public function __construct(array $sort = [])
	{
		parent::__construct($sort);
	}

	public function by(string $camelName, string $direction = 'ASC'): static
	{
		$this->sort[$camelName] = strcasecmp($direction, 'DESC') === 0 ? 'DESC' : 'ASC';

		return $this;
	}

	public function asc(string $camelName): static
	{
		return $this->by($camelName, 'ASC');
	}

	public function desc(string $camelName): static
	{
		return $this->by($camelName, 'DESC');
	}

	public function isEmpty(): bool
	{
		return empty($this->sort);
	}

	/**
	 * The raw camelCase ordering as supplied by the caller. The Provider resolves names to ORM
	 * column names via FieldRegistry.
	 *
	 * @return array<string, string>
	 */
	public function getRaw(): array
	{
		return $this->sort;
	}

	/**
	 * Implements {@see \Bitrix\Main\Provider\Params\SortInterface} via the parent {@see Sort}.
	 * Returns the raw camelCase map — V2 Providers use {@see getRaw()} instead and translate
	 * names themselves.
	 *
	 * @return array<string, string>
	 */
	public function prepareSort(): array
	{
		return $this->sort;
	}

	protected function getAllowedFields(): array
	{
		// Parent's intersect-key filtering is bypassed in prepareSort(); allowed-fields is unused.
		return [];
	}
}

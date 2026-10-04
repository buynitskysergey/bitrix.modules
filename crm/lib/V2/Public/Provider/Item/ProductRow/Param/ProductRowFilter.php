<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item\ProductRow\Param;

/**
 * Caller-side spec for the WHERE clause of ProductRow reads.
 *
 * The set of filterable fields is closed and it is part of the access model, not a query
 * optimisation: an owner is always required, and nothing else than the fields below can be
 * expressed at all. Publishing a field in the response does not make it filterable.
 *
 * The owner type is not part of the filter - it comes from the trusted route and is passed to the
 * read method separately.
 *
 * Example:
 * ```php
 * new ProductRowFilter(ownerId: 42, productIds: [10, 11]);
 * ```
 */
final class ProductRowFilter
{
	/** @var int[]|null */
	private readonly ?array $ids;

	/** @var int[]|null */
	private readonly ?array $productIds;

	/**
	 * @param int $ownerId Required: rows are always read within a single owner.
	 * @param int[]|null $ids `null` - no restriction; an empty array matches no row.
	 * @param int[]|null $productIds `null` - no restriction; an empty array matches no row.
	 */
	public function __construct(
		private readonly int $ownerId,
		?array $ids = null,
		?array $productIds = null,
	)
	{
		$this->ids = $ids === null ? null : self::normalize($ids);
		$this->productIds = $productIds === null ? null : self::normalize($productIds);
	}

	public function getOwnerId(): int
	{
		return $this->ownerId;
	}

	/** @return int[]|null */
	public function getIds(): ?array
	{
		return $this->ids;
	}

	/** @return int[]|null */
	public function getProductIds(): ?array
	{
		return $this->productIds;
	}

	/**
	 * @param mixed[] $values
	 * @return int[]
	 */
	private static function normalize(array $values): array
	{
		return array_values(array_unique(array_map('intval', $values)));
	}
}

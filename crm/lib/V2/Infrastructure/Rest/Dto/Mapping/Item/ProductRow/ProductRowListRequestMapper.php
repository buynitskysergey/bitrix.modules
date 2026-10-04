<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\ProductRow;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListOrderStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListPaginationStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ProductRowListFilterStructure;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\Param\ProductRowFilter;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\Param\ProductRowSort;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;

/**
 * Translates the parts of a list request into the read parameters of
 * {@see \Bitrix\Crm\V2\Public\Provider\Item\ProductRow\ProductRowProvider::getList()}.
 *
 * Two things this mapper deliberately does **not** do:
 *
 * - it does not append a tie-breaker to the ordering. {@see ProductRowSort} carries the primary key on
 *   every path already, an empty ordering included, so doing it again here would only be a second
 *   place to keep in step;
 * - it does not translate `select`. The provider reads a whole row and the projection happens when the
 *   DTO is built, in {@see ProductRowDtoMapper}.
 *
 * The owner type is not part of the filter either: it comes from the trusted route and is passed to
 * the provider next to the filter.
 */
final class ProductRowListRequestMapper
{
	public function mapFilter(ProductRowListFilterStructure $filter): ProductRowFilter
	{
		return new ProductRowFilter(
			$filter->getOwnerId(),
			$filter->getIds(),
			$filter->getProductIds(),
		);
	}

	/**
	 * @throws \Bitrix\Crm\V2\Public\Provider\Item\Exception\UnknownSortFieldException
	 */
	public function mapSort(?ItemListOrderStructure $order): ProductRowSort
	{
		return new ProductRowSort($order?->getList() ?? []);
	}

	/**
	 * The framework caps the page from above and rejects a zero size, but lets a negative one through;
	 * the lower bound is checked here, as it is for the list of Items.
	 *
	 * @throws InvalidPaginationException
	 */
	public function mapPager(?ItemListPaginationStructure $pagination): Pager
	{
		if ($pagination === null)
		{
			return new Pager();
		}

		$limit = $pagination->getLimit();
		$offset = $pagination->getOffset();
		if ($limit <= 0)
		{
			throw new InvalidPaginationException(['limit' => $limit]);
		}
		if ($offset < 0)
		{
			throw new InvalidPaginationException(['offset' => $offset]);
		}

		return new Pager($limit, $offset);
	}
}

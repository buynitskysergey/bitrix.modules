<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Product;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListOrderStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListPaginationStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectStructure;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

/**
 * `crm.product.list`.
 *
 * The standard shape of a list request, with every part optional. A card of the catalog has no parent, so
 * the filter is not required here: reading the whole catalog is the ordinary call of this method, and the
 * required filter of `crm.{entity}.productRow.list` exists because a row is only ever read within its
 * owner. A card is not a row and has no owner to name.
 *
 * The filter is the standard structure of REST v3 - the catalog has none of the compound fields
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListFilterStructure} exists for, and no
 * field of its own to require. What the standard structure allows and a card cannot express - an
 * expression as an operand - is refused by
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Product\ProductListRequestMapper}, where the filter
 * becomes a parameter of the read.
 *
 * The lower bound of the page size is not restated here either. The framework caps the size from above and
 * lets a negative one through, and the one place that refuses it is
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Product\ProductListRequestMapper::mapPager()}.
 */
final class ListProductRequest extends Request
{
	public ?SelectStructure $select = null;

	public ?FilterStructure $filter = null;

	public ?ItemListOrderStructure $order = null;

	public ?ItemListPaginationStructure $pagination = null;
}

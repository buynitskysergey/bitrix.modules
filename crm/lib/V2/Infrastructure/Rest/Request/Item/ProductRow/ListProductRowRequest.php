<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListOrderStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListPaginationStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ProductRowListFilterStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectStructure;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.{entity}.productRow.list`.
 *
 * Rows are always read within one owner, so the filter is not optional: the property is typed and has no
 * default, which is how {@see Request::create()} is told that a request without it is incomplete. The owner
 * inside the filter is required by {@see ProductRowListFilterStructure}, which also explains why the
 * framework attribute cannot state it here. A missing filter and a filter without an owner are two
 * different answers, and both are validation errors of the request.
 *
 * The owner does not appear beside the filter. A product row is a child resource, its parent belongs in the
 * filter of a list, and a scalar `ownerId` of its own next to a list request is the shape
 * `crm.{entity}.timeline.activity.email.list` has and the one this group deliberately does not repeat.
 *
 * The lower bound of the page size is not restated here. The framework caps the size from above and lets a
 * negative one through, and the one place that refuses it is
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\ProductRow\ProductRowListRequestMapper::mapPager()},
 * where the page becomes a parameter of the read.
 */
final class ListProductRowRequest extends Request
{
	public ?SelectStructure $select = null;

	public ProductRowListFilterStructure $filter;

	public ?ItemListOrderStructure $order = null;

	public ?ItemListPaginationStructure $pagination = null;
}

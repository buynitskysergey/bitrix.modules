<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Category;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\CategoryPaginationStructure;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.<entity>.category.list`: paging and nothing else.
 *
 * Neither `filter` nor `order` is published, and that is a decision rather than an omission. The
 * category provider of the domain takes neither: it always answers in the order of the domain - by
 * `sort`, then by identifier - over the categories the caller may read. Publishing an ability the
 * scenario does not have is forbidden; publishing sorting later is a compatible change, taking it
 * back is not. `select` is absent for the same reason as in {@see GetCategoryRequest}.
 */
final class ListCategoryRequest extends Request
{
	public ?CategoryPaginationStructure $pagination = null;
}

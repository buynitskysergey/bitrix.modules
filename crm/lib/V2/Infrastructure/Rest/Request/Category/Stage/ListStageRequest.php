<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\CategoryPaginationStructure;
use Bitrix\Crm\V2\Public\Entity\Category\Stage;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

/**
 * `crm.<entity>.category.stage.list`: the stages of one category.
 *
 * A stage means nothing outside the category it stands in, so the category is not optional: the
 * filter is a required property and it must name `categoryId` by plain equality -
 * `"filter": ["categoryId", "=", 2]`. Nothing else satisfies that: neither a range nor a list of
 * categories counts as naming one ({@see FilterRequired} reads the equalities of the top level
 * alone), and a client that sends either gets a refusal that names the field it is missing.
 * `categoryId` is also the only field the stages can be filtered by at all - every other field of the
 * contract refuses a filter outright.
 *
 * Neither `order` nor `select` is published, and that is a decision rather than an omission. The
 * stage provider of the domain takes neither: it always answers the whole of a stage, in the order
 * the stages stand in. Publishing an ability the scenario does not have is forbidden; publishing
 * sorting later is a compatible change, taking it back is not.
 *
 * The paging is the one of the family ({@see CategoryPaginationStructure}). A category holds a
 * handful of stages, so the hundred it allows already stands far above the whole set of any category
 * and no ceiling of its own would buy a client anything.
 */
final class ListStageRequest extends Request
{
	#[FilterRequired([Stage::categoryId])]
	public FilterStructure $filter;

	public ?CategoryPaginationStructure $pagination = null;
}

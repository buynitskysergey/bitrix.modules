<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListOrderStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListPaginationStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectStructure;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

/**
 * `crm.requisite.list`.
 *
 * The standard shape of a list request, with every part optional. The method reads the requisites of the
 * whole portal, so it has no parent to name and no filter to require: reading them all is its ordinary
 * call, unlike `crm.{entity}.productRow.list`, where a row only ever exists within its owner.
 *
 * The filter is the standard structure of REST v3 - a requisite has none of the compound fields
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListFilterStructure} exists for. What the
 * standard structure allows and the read cannot express - an expression as an operand - and the one
 * subject refusal of the filter - an owner of a type the method does not serve - both belong to
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\RequisiteListRequestMapper}, where the
 * filter becomes a parameter of the read.
 *
 * The lower bound of the page size is not restated here either. The framework caps the size from above and
 * lets a negative one through, and the one place that refuses it is
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\RequisiteListRequestMapper::mapPager()}.
 */
final class ListRequisiteRequest extends Request
{
	public ?SelectStructure $select = null;

	public ?FilterStructure $filter = null;

	public ?ItemListOrderStructure $order = null;

	public ?ItemListPaginationStructure $pagination = null;
}

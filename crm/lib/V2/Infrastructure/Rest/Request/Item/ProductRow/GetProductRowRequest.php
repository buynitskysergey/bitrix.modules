<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectStructure;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.{entity}.productRow.get`.
 *
 * `select` keeps the meaning it has everywhere else in CRM: absent means the minimum,
 * an empty list means an empty row, an explicit list means exactly the fields listed. The projection
 * itself is done when the DTO is built, in
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\ProductRow\ProductRowDtoMapper}; an unknown
 * field is refused here, while the structure is built.
 *
 * The identifier of a row is unique across owners, so the owner is not part of this request. That the row
 * belongs to an owner of the type of the route, and that the owner is one the client may read, is checked
 * by the scenario.
 */
final class GetProductRowRequest extends Request
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public ?SelectStructure $select = null;
}

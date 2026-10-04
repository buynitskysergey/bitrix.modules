<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectStructure;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.{entity}.productRow.getAvailableForPayment` - the elements of a parent that are still to be paid.
 *
 * One property, and it is the parent: the shape of the request says nothing about product rows, and the
 * same form serves the unpaid part of any nested collection. The element type comes from the DTO of the
 * route.
 *
 * No paging. A page is what keeps an unbounded read bounded, and this read is bounded already - it answers
 * the composition of a single owner, which the request is required to name.
 *
 * `select` keeps the meaning it has in {@see GetProductRowRequest}. It is worth having here for the same
 * reason the answer is worth reading at all: the quantity of every element is the payable one rather than
 * the stored one, and a projection down to the identifier alone would leave that out.
 */
final class AvailableForPaymentRequest extends Request
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $ownerId;

	public ?SelectStructure $select = null;
}

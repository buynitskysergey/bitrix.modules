<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Category;

use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.<entity>.category.get`: which category to read.
 *
 * A category of its own rather than the standard `GetRequest`, which calls the identifier a string
 * while a category is addressed by a number. There is no `select` either: the contract of a category
 * is a handful of fields the provider reads whole, so a client has nothing to pick from.
 */
final class GetCategoryRequest extends Request
{
	#[Min(0)]
	public int $id;
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Category;

use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.<entity>.category.delete`: which category to remove.
 *
 * As in {@see UpdateCategoryRequest}, the identifier is a number and there is no `filter`: deleting
 * a set is not a hidden ability of a typical method of CRM.
 */
final class DeleteCategoryRequest extends Request
{
	#[Min(0)]
	public int $id;
}

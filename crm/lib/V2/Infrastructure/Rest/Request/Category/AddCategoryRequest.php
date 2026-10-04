<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Category;

use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * `crm.<entity>.category.add`: the fields of a new category.
 *
 * Which of them may be written is the business of the generated DTO of the route rather than of the
 * request: the composition differs by entity type, and `convertToDto()` refuses a field the type
 * does not write.
 */
final class AddCategoryRequest extends Request
{
	public FieldsStructure $fields;
}

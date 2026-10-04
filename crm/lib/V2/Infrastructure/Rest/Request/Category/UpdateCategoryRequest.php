<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Category;

use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * `crm.<entity>.category.update`: which category and what to write into it.
 *
 * A category of its own rather than the standard `UpdateRequest` for two reasons. The identifier is
 * a number here - `0` among its values, the default category of Deal - while the standard one calls
 * it a string. And the standard one addresses a set by `filter`, which a typical method of CRM is
 * forbidden to do: the property is absent here, not empty.
 *
 * Empty `fields` is a valid request rather than an error: it asks for no write, and answering it
 * still takes checking that the category exists and may be changed.
 */
final class UpdateCategoryRequest extends Request
{
	#[Min(0)]
	public int $id;

	public FieldsStructure $fields;
}

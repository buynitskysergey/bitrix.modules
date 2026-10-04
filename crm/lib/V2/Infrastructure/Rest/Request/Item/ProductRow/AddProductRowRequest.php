<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow;

use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * `crm.{entity}.productRow.add`.
 *
 * The owner travels inside `fields`, as `fields.ownerId`, and not beside them: a product row is a child
 * resource, and the parent of an added child belongs among its fields. The owner type is not part of the
 * request at all - it comes from the trusted route.
 *
 * Whether the client sent an owner, whether it may write to it, and whether every field it sent is
 * writable are questions of the subject matter: the fields reach the scenario as they were sent, and the
 * scenario answers them.
 */
final class AddProductRowRequest extends Request
{
	public FieldsStructure $fields;
}

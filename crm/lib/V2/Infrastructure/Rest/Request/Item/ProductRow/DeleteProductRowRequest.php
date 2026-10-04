<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow;

/**
 * `crm.{entity}.productRow.delete`. The identifier and the refusal of a filter are the whole contract, and
 * both come from {@see AbstractProductRowIdRequest}; the class exists because an action is wired to a
 * request by its own type.
 *
 * Deleting a set is not a hidden ability of this method. Should it ever be needed, it is a contract of its
 * own, agreed as such.
 */
final class DeleteProductRowRequest extends AbstractProductRowIdRequest
{
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Response\Item\ProductRow;

use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Response\Response;

/**
 * The answer of `crm.{entity}.productRow.replace`: the set the owner ended up with, after normalization.
 *
 * A set, and not a sign of success: identifiers do not survive a change of a row, so the client cannot know
 * what its owner now holds unless it is told. The result field is named as everywhere else a collection is
 * answered - `items`; the envelope around it, `result` and the rest, is REST v3's, and this group adds no
 * `total`, `next`, `start`, `success` or `data` of its own.
 *
 * Not a {@see \Bitrix\Rest\V3\Interaction\Response\ListResponse}: replacing is not reading a page, and the
 * relation machinery of a list response has nothing to do here.
 *
 * Projection is the one every write of this group has:
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow\ReplaceProductRowsRequest} declares a
 * `select`, as the request of a change does, so a row carries the fields that were asked for - its
 * identifier alone when none were, or the fields the token is restricted to, when it is restricted.
 */
final class ReplaceProductRowsResponse extends Response
{
	public function __construct(public DtoCollection $items)
	{
	}
}

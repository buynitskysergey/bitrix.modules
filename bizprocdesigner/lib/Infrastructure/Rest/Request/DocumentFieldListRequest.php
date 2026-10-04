<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Request;

use Bitrix\Rest\V3\Interaction\Request\ListRequest;

/**
 * Reading the fields of the document the bound template runs on.
 *
 * A search phrase is not a predicate over the full set: it switches the read to the semantic search
 * service, which answers with the fields closest to the phrase. Hence a field of its own and not filter.
 */
final class DocumentFieldListRequest extends ListRequest
{
	public ?string $search = null;
}

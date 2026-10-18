<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Request;

use Bitrix\Note\Infrastructure\Rest\V3\Structure\SearchPaginationStructure;
use Bitrix\Rest\V3\Documentation\Attributes\Deprecated;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

class SearchDocumentsRequest extends Request
{
	public ?SelectStructure $select = null;

	/** Selection goes here: `query` - the search string, `collectionId` - the collection to search in. */
	public ?FilterStructure $filter = null;

	/**
	 * The search string as it used to be passed: a parameter of its own, next to the pagination.
	 * Still accepted so nobody breaks mid-migration; the filter wins when both are given.
	 */
	#[Deprecated]
	public ?string $query = null;

	public ?SearchPaginationStructure $pagination = null;
}

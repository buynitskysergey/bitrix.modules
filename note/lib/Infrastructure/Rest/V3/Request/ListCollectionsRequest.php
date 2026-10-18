<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Request;

use Bitrix\Note\Infrastructure\Rest\V3\Structure\CollectionPaginationStructure;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

class ListCollectionsRequest extends Request
{
	public ?SelectStructure $select = null;

	public ?CollectionPaginationStructure $pagination = null;
}

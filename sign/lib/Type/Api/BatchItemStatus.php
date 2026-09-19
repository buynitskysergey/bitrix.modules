<?php

namespace Bitrix\Sign\Type\Api;

/**
 * Outcome of a single item of a batch response, as the service puts it in `data.results[].status`.
 *
 * The wire values belong to the service contract and the two sides are released apart, so a value the
 * portal does not know is not a case here: it is read as `null` (see Item\Api\Batch\ItemResult) and
 * counts as a failure until the portal learns it.
 */
enum BatchItemStatus: string
{
	case SUCCESS = 'success';
	case FAILED = 'failed';
	case ALREADY_DONE = 'already_done';
}

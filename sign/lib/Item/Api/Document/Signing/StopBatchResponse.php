<?php

namespace Bitrix\Sign\Item\Api\Document\Signing;

use Bitrix\Sign\Item;

/**
 * Item outcomes come in the payload, while inherited errors mean the whole request was rejected
 * and there is nothing to read per item. Stopping is a document level action, so `memberUid` of
 * an item result is always null.
 */
class StopBatchResponse extends Item\Api\Response
{
	/**
	 * @param Item\Api\Batch\ItemResult[] $results in the order of the requested document uids
	 */
	public function __construct(
		public array $results = [],
	)
	{}
}

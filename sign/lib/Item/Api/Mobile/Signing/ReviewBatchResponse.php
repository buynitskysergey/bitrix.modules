<?php

namespace Bitrix\Sign\Item\Api\Mobile\Signing;

use Bitrix\Sign\Item;

/**
 * Item outcomes come in the payload, while inherited errors mean the whole request was rejected
 * and there is nothing to read per item.
 */
class ReviewBatchResponse extends Item\Api\Response
{
	/**
	 * @param Item\Api\Batch\ItemResult[] $results in the order of the requested items
	 */
	public function __construct(
		public array $results = [],
	)
	{}
}

<?php

namespace Bitrix\Sign\Item\Api\Mobile\Signing;

use Bitrix\Sign\Contract;

/**
 * `items` is the only top level key of the request body: the receiving action takes the rest of
 * its arguments from the same body, so any other key here would mix with them.
 */
class ReviewBatchRequest implements Contract\Item
{
	/** @var list<array{documentId: string, memberId: string}> */
	public readonly array $items;

	/**
	 * @param array<array{documentId: string, memberId: string}> $items
	 */
	public function __construct(array $items)
	{
		$this->items = array_values($items);
	}
}

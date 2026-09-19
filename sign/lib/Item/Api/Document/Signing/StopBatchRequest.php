<?php

namespace Bitrix\Sign\Item\Api\Document\Signing;

use Bitrix\Sign\Contract;

/**
 * `documentIds` is the only top level key of the request body: the receiving action takes the rest
 * of its arguments from the same body, so any other key here would mix with them.
 */
class StopBatchRequest implements Contract\Item
{
	/** @var list<string> */
	public readonly array $documentIds;

	/**
	 * @param array<string> $documentIds document uids
	 */
	public function __construct(array $documentIds)
	{
		$this->documentIds = array_values($documentIds);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Exceptions;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * A refusal of its own rather than a plain "no access": the caller has every right the write asks
 * for - they are the very person who may dispose of the base in the bin - and what stands in the way
 * is the state of the base. "No access" would send them asking for a right they already hold,
 * instead of taking the base out of the bin and repeating the call.
 */
class CollectionInRecycleBinException extends RestException implements SkipWriteToLogException
{
	protected const STATUS = '409 Conflict';

	public function getRegistryCode(): string
	{
		return 'NOTE_COLLECTION_IN_RECYCLE_BIN';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'NOTE_REST_V3_EXCEPTION_COLLECTIONINRECYCLEBINEXCEPTION_MSG';
	}
}

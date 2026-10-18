<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Exceptions;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

class CollectionUnderImportException extends RestException implements SkipWriteToLogException
{
	protected const STATUS = '409 Conflict';

	public function getRegistryCode(): string
	{
		return 'NOTE_COLLECTION_UNDER_IMPORT';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'NOTE_REST_V3_EXCEPTION_COLLECTIONUNDERIMPORTEXCEPTION_MSG';
	}
}

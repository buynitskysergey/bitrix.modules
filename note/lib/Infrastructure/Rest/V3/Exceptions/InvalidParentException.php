<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Exceptions;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

class InvalidParentException extends RestException implements SkipWriteToLogException
{
	public function getRegistryCode(): string
	{
		return 'NOTE_INVALID_PARENT';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'NOTE_REST_V3_EXCEPTION_INVALIDPARENTEXCEPTION_MSG';
	}
}

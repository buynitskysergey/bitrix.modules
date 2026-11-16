<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Exceptions;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * A refusal of its own rather than a plain "bad request": the document in the bin has no base left to
 * go back to, so the caller has to name one. Telling this apart is what lets a client ask where to put
 * the document instead of repeating a request that will never pass.
 */
class RestoreTargetRequiredException extends RestException implements SkipWriteToLogException
{
	public function getRegistryCode(): string
	{
		return 'NOTE_RESTORE_TARGET_REQUIRED';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'NOTE_REST_V3_EXCEPTION_RESTORETARGETREQUIREDEXCEPTION_MSG';
	}
}

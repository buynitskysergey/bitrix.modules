<?php

namespace Bitrix\Rest\V3\Exception;

use CRestServer;

class InvalidIdempotencyKeyException extends RestException
{
	protected const STATUS = CRestServer::STATUS_WRONG_REQUEST;

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_INVALIDIDEMPOTENCYKEYEXCEPTION';
	}
}

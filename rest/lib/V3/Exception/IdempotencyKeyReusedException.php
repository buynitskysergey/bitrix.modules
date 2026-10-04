<?php

namespace Bitrix\Rest\V3\Exception;

class IdempotencyKeyReusedException extends RestException
{
	protected const STATUS = '422 Unprocessable Entity';

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_IDEMPOTENCYKEYREUSEDEXCEPTION';
	}
}

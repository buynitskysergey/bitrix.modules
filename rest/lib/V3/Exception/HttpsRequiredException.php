<?php

namespace Bitrix\Rest\V3\Exception;

class HttpsRequiredException extends RestException implements SkipWriteToLogException
{
	protected const STATUS = \CRestServer::STATUS_WRONG_REQUEST;

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_HTTPSREQUIREDEXCEPTION';
	}
}

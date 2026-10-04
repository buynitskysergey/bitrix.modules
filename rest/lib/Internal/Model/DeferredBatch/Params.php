<?php

namespace Bitrix\Rest\Internal\Model\DeferredBatch;

enum Params: string
{
	case Language = 'language';
	case Query = 'query';
	case Scopes = 'scopes';
}

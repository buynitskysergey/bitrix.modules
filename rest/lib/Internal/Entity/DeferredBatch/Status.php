<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\DeferredBatch;

enum Status: string
{
	case Pending = 'pending';
	case Processing = 'processing';
	case Done = 'done';
	case Error = 'error';
}


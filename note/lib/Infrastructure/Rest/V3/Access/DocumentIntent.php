<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Access;

/**
 * What the caller is about to do with the document. The resolver answers differently for the two:
 * an archived or trashed document may be read but never written.
 */
enum DocumentIntent
{
	case Read;
	case Write;
}

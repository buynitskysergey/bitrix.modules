<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\RestException;

/**
 * The block catalog cannot be built at all: the bizproc module it is read from is not available.
 *
 * Such a portal is refused by the availability prefilter before any action runs, so this is the type guard
 * of the union the listing returns rather than an answer an agent receives. Reaching it means the portal
 * changed mid-request or the prefilter stopped guarding - an incident, so it reaches the exception log.
 *
 * The message names no state of the portal: what is installed there is not for a caller to read.
 */
final class BlockCatalogUnavailableException extends RestException
{
	use RussianMessageFallback;

	protected const STATUS = \CRestServer::STATUS_INTERNAL;

	protected function getMessagePhraseCode(): string
	{
		return 'BIZPROCDESIGNER_REST_EXCEPTION_BLOCK_CATALOG_UNAVAILABLE';
	}
}

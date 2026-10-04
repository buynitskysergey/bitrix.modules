<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\Validation\ValidationException;

/**
 * Refusal of a graph the domain validators rejected.
 *
 * The errors are handed to the core as they are: the address of every problem already sits in Error::code,
 * and that is the field the core fills validation[].field from - the very field it fills for a malformed
 * request body. An error about the graph as a whole carries no address and answers without a field.
 *
 * Being a ValidationException, it is a user error and stays out of the exception log.
 */
final class GraphValidationException extends ValidationException
{
	use RussianMessageFallback;

	protected function getMessagePhraseCode(): string
	{
		return 'BIZPROCDESIGNER_REST_EXCEPTION_GRAPH_VALIDATION';
	}
}
